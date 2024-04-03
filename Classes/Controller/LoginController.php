<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\Oidc\Controller;

use Causal\Oidc\Service\OAuthService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\PropagateResponseException;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class LoginController
{
    /**
     * Global oidc settings
     *
     * @var array
     */
    protected array $settings;

    /**
     * TypoScript configuration of this plugin
     *
     * @var array
     */
    protected array $pluginConfiguration = [];

    /**
     * @var ContentObjectRenderer|null will automatically be injected, if this controller is called as a plugin
     */
    public ?ContentObjectRenderer $cObj = null;

    public function __construct()
    {
        $this->settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('oidc') ?? [];
    }

    public function setContentObjectRenderer(ContentObjectRenderer $cObj)
    {
        $this->cObj = $cObj;
    }

    /**
     * Main entry point for the OIDC plugin.
     *
     * If the user is not logged in, redirect to the authorization server to start the oidc process
     *
     * If the user has just been logged in and just came back from the authorization server, redirect the user to the
     * final redirect URL.
     *
     * @param string $_ ignored
     * @param array|null $pluginConfiguration
     */
    public function login(string $_, ?array $pluginConfiguration)
    {
        if (is_array($pluginConfiguration)) {
            $this->pluginConfiguration = $pluginConfiguration;
        }

        $request = ServerRequestFactory::fromGlobals();
        $loginType = $request->getParsedBody()['logintype'] ?? $request->getQueryParams()['logintype'] ?? '';
        if ($loginType === 'login') {
            // performRedirectAfterLogin stops flow by emitting a redirect
            $this->performRedirectAfterLogin();
        }
        $this->performRedirectToLogin(
            isset($pluginConfiguration['authorizationUrlOptions.'])  ? $pluginConfiguration['authorizationUrlOptions.']: []
        );
    }

    protected function performRedirectToLogin(array $authorizationUrlOptions = [])
    {
        /** @var OAuthService $service */
        $service = GeneralUtility::makeInstance(OAuthService::class);
        $service->setSettings($this->settings);

        if (session_id() === '') {
            session_start();
        }
        $options = [];
        if ($this->settings['enableCodeVerifier'] ?? false) {
            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->convertVerifierToChallenge($codeVerifier);
            $options = $this->addCodeChallengeToOptions($codeChallenge, $authorizationUrlOptions);
            $_SESSION['oidc_code_verifier'] = $codeVerifier;
        }
        $authorizationUrl = $service->getAuthorizationUrl($options);

        $state = $service->getState();
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_login_url'] = GeneralUtility::getIndpEnv('REQUEST_URI');
        $_SESSION['oidc_authorization_url'] = $authorizationUrl;
        unset($_SESSION['oidc_redirect_url']); // The redirect will be handled by this plugin

        $this->redirect($authorizationUrl);
    }

    protected function performRedirectAfterLogin()
    {
        $redirectUrl = $this->determineRedirectUrl();
        $this->redirect($redirectUrl);
    }

    protected function determineRedirectUrl()
    {
        $request = $GLOBALS['TYPO3_REQUEST'];
        $redirectUrl = $request->getParsedBody()['redirect_url'] ?? $request->getQueryParams()['redirect_url'] ?? '';
        if (!empty($redirectUrl)) {
            return $redirectUrl;
        }

        if (isset($this->pluginConfiguration['defaultRedirectPid'])) {
            $defaultRedirectPid = $this->pluginConfiguration['defaultRedirectPid'];
            if ((int)$defaultRedirectPid > 0) {
                return $this->cObj->typoLink_URL(['parameter' => $defaultRedirectPid]);
            }
        }

        return '/';
    }

    protected function redirect(string $redirectUrl): void
    {
        throw new PropagateResponseException(new RedirectResponse($redirectUrl));
    }

    protected function generateCodeVerifier(): string
    {
        return bin2hex(random_bytes(64));
    }

    protected function convertVerifierToChallenge($codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    protected function addCodeChallengeToOptions($codeChallenge, array $options = []): array
    {
        return array_merge(
            $options,
            [
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
            ]
        );
    }
}
