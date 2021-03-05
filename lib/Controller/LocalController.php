<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Circles\Controller;


use daita\MySmallPhpTools\Traits\Nextcloud\nc21\TNC21Deserialize;
use daita\MySmallPhpTools\Traits\Nextcloud\nc21\TNC21Logger;
use Exception;
use OCA\Circles\Exceptions\FederatedItemException;
use OCA\Circles\Exceptions\FederatedUserException;
use OCA\Circles\Exceptions\FederatedUserNotFoundException;
use OCA\Circles\Exceptions\InvalidIdException;
use OCA\Circles\Exceptions\SingleCircleNotFoundException;
use OCA\Circles\Model\Member;
use OCA\Circles\Service\CircleService;
use OCA\Circles\Service\ConfigService;
use OCA\Circles\Service\FederatedUserService;
use OCA\Circles\Service\MemberService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;


/**
 * Class LocalController
 *
 * @package OCA\Circles\Controller
 */
class LocalController extends OcsController {


	use TNC21Deserialize;
	use TNC21Logger;


	/** @var IUserSession */
	private $userSession;

	/** @var FederatedUserService */
	private $federatedUserService;

	/** @var CircleService */
	private $circleService;

	/** @var MemberService */
	private $memberService;

	/** @var ConfigService */
	protected $configService;


	/**
	 * BaseController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IUserSession $userSession
	 * @param FederatedUserService $federatedUserService
	 * @param CircleService $circleService
	 * @param MemberService $memberService
	 * @param ConfigService $configService
	 */
	public function __construct(
		string $appName, IRequest $request, IUserSession $userSession,
		FederatedUserService $federatedUserService, CircleService $circleService,
		MemberService $memberService, ConfigService $configService
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->federatedUserService = $federatedUserService;
		$this->circleService = $circleService;
		$this->memberService = $memberService;
		$this->configService = $configService;

		$this->setup('app', 'circles');
	}


	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function circles(): DataResponse {
		$debug = ['action' => 'localController::circles()'];

		try {
			$this->setCurrentFederatedUser();

			$data = $this->circleService->getCircles();
			$this->debug('success', array_merge($debug, ['data' => $data]));

			return new DataResponse(json_decode(json_encode($data), true));

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $name
	 * @param bool $personal
	 *
	 * @return DataResponse
	 */
	public function create(string $name, bool $personal = false): DataResponse {
		$debug = ['action' => 'localController::create()', 'name' => $name, 'personal' => $personal];

		try {
			$this->setCurrentFederatedUser();
			try {
				$circle = $this->circleService->create($name);
				$this->debug('success', array_merge($debug, ['circle' => $circle]));

				return new DataResponse(json_decode(json_encode($circle), true));
			} catch (FederatedItemException $e) {
				$this->e($e, array_merge($debug, ['e' => $e]));

				return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
			}

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $circleId
	 *
	 * @return DataResponse
	 */
	public function members(string $circleId): DataResponse {
		$debug = ['action' => 'localController::members()', 'circleId' => $circleId];

		try {
			$this->setCurrentFederatedUser();
			$members = $this->memberService->getMembers($circleId);
			$this->debug('success', array_merge($debug, ['members' => $members]));

			return new DataResponse(json_decode(json_encode($members), true));

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $circleId
	 * @param string $userId
	 * @param int $type
	 *
	 * @return DataResponse
	 */
	public function memberAdd(string $circleId, string $userId, int $type): DataResponse {
		$debug = [
			'action'   => 'localController::memberAdd()',
			'circleId' => $circleId,
			'userId'   => $userId,
			'type'     => $type
		];

		try {
			$this->setCurrentFederatedUser();

			try {
				$member = $this->federatedUserService->generateFederatedUser($userId, (int)$type);
				$result = $this->memberService->addMember($circleId, $member);
				$this->debug('success', array_merge($debug, ['result' => $result]));

				return new DataResponse(json_decode(json_encode($result), true));
			} catch (FederatedItemException $e) {
				$this->e($e, array_merge($debug, ['e' => $e]));

				return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
			}

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $circleId
	 *
	 * @return DataResponse
	 */
	public function circleJoin(string $circleId): DataResponse {
		$debug = [
			'action'   => 'localController::circleJoin()',
			'circleId' => $circleId
		];

		try {
			$this->setCurrentFederatedUser();

			try {
				$result = $this->circleService->circleJoin($circleId);
				$this->debug('success', array_merge($debug, ['result' => $result]));

				return new DataResponse(json_decode(json_encode($result), true));
			} catch (FederatedItemException $e) {
				$this->e($e, array_merge($debug, ['e' => $e]));

				return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
			}

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $circleId
	 * @param string $memberId
	 * @param string $level
	 *
	 * @return DataResponse
	 */
	public function memberLevel(string $circleId, string $memberId, string $level): DataResponse {
		$debug = [
			'action'   => 'localController::memberLevel()',
			'circleId' => $circleId,
			'memberId' => $memberId,
			'level'    => $level
		];

		try {
			$this->setCurrentFederatedUser();

			try {
				$level = Member::parseLevelString($level);
				$this->memberService->getMember($memberId, $circleId);
				$result = $this->memberService->memberLevel($memberId, $level);
				$this->debug('success', array_merge($debug, ['result' => $result]));

				return new DataResponse(json_decode(json_encode($result), true));
			} catch (FederatedItemException $e) {
				$this->e($e, array_merge($debug, ['e' => $e]));

				return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
			}

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}


	/**
	 * @NoAdminRequired
	 *
	 * @param string $circleId
	 * @param string $memberId
	 *
	 * @return DataResponse
	 */
	public function memberRemove(string $circleId, string $memberId): DataResponse {
		$debug = [
			'action'   => 'localController::memberRemove()',
			'circleId' => $circleId,
			'memberId' => $memberId
		];

		try {
			$this->setCurrentFederatedUser();

			try {
				$this->memberService->getMember($memberId, $circleId);
				$result = $this->memberService->removeMember($memberId);
				$this->debug('success', array_merge($debug, ['result' => $result]));

				return new DataResponse(json_decode(json_encode($result), true));
			} catch (FederatedItemException $e) {
				$this->e($e, array_merge($debug, ['e' => $e]));

				return new DataResponse(['error' => $e->getMessage()], $e->getStatus());
			}

		} catch (Exception $e) {
			$this->e($e, array_merge($debug, [Http::STATUS_INTERNAL_SERVER_ERROR]));

			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}


	/**
	 * @throws FederatedUserNotFoundException
	 * @throws InvalidIdException
	 * @throws FederatedUserException
	 * @throws SingleCircleNotFoundException
	 */
	private function setCurrentFederatedUser() {
		$user = $this->userSession->getUser();
		$this->federatedUserService->setLocalCurrentUser($user);
	}

}

