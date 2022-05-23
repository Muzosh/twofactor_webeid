<?php

/**
 *
 * @copyright Copyright (c) 2022, Petr Muzikant (petr.muzikant@vut.cz)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\TwoFactorWebEid\Service;

use GuzzleHttp\Psr7\Uri;
use muzosh\web_eid_authtoken_validation_php\certificate\CertificateData;
use muzosh\web_eid_authtoken_validation_php\certificate\CertificateLoader;
use muzosh\web_eid_authtoken_validation_php\challenge\ChallengeNonceGenerator;
use muzosh\web_eid_authtoken_validation_php\challenge\ChallengeNonceGeneratorBuilder;
use muzosh\web_eid_authtoken_validation_php\challenge\ChallengeNonceStore;
use muzosh\web_eid_authtoken_validation_php\validator\AuthTokenValidator;
use muzosh\web_eid_authtoken_validation_php\validator\AuthTokenValidatorBuilder;
use OCP\ISession;
use OCP\IUser;
use phpseclib3\File\X509;
use Psr\Log\LoggerInterface;

class WebEidService {
	private const CHALLENGE_NONCE_TTL_SECONDS = 300;

	/** @var ISession */
	private $session;
	
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		LoggerInterface $logger,
		ISession $session
	) {
		$this->session = $session;
		$this->logger = $logger;
	}

	public function authenticate(X509 $cert, IUser $user): bool {
		$certCN = CertificateData::getSubjectCN($cert);

		if ($user->getUID() == $certCN) {
			return true;
		}

		$this->logger->error(
			'WebEid authtoken validation successful, but CommonName does not match. UserID: '.
			$user->getUID().
			', CN: '.
			$certCN
		);

		return false;
	}

	public function getSessionBasedChallengeNonceStore(): ChallengeNonceStore {
		return new SessionBackedChallengeNonceStore($this->session);
	}

	public function getGenerator(ChallengeNonceStore $challengeNonceStore): ChallengeNonceGenerator {
		return (new ChallengeNonceGeneratorBuilder())
			->withNonceTtl(self::CHALLENGE_NONCE_TTL_SECONDS)
			->withChallengeNonceStore($challengeNonceStore)
			->build()
		;
	}

	public function loadTrustedCACertificatesFromCertFiles(): array {
		// TODO: put cert path into some config
		$pathnames = array_map(
			'basename',
			glob(__DIR__.'/../../trustedcerts/*.{crt,cer,pem,der}', GLOB_BRACE)
		);

		return CertificateLoader::loadCertificatesFromPath(__DIR__.'/../../trustedcerts', ...$pathnames);
	}

	public function getValidator(): AuthTokenValidator {
		// TODO: put site-origin into some config?
		return (new AuthTokenValidatorBuilder())
			->withSiteOrigin(new Uri('https://'.$_SERVER['SERVER_ADDR']))
			->withTrustedCertificateAuthorities(...self::loadTrustedCACertificatesFromCertFiles())
			->withoutUserCertificateRevocationCheckWithOcsp()
			->build()
		;
	}
}
