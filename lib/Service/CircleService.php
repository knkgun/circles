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


namespace OCA\Circles\Service;


use daita\MySmallPhpTools\Exceptions\InvalidItemException;
use daita\MySmallPhpTools\Exceptions\RequestNetworkException;
use daita\MySmallPhpTools\Exceptions\SignatoryException;
use daita\MySmallPhpTools\Model\Request;
use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TStringTools;
use OCA\Circles\Db\CircleRequest;
use OCA\Circles\Db\MemberRequest;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Exceptions\FederatedEventException;
use OCA\Circles\Exceptions\InitiatorNotConfirmedException;
use OCA\Circles\Exceptions\InitiatorNotFoundException;
use OCA\Circles\Exceptions\MembersLimitException;
use OCA\Circles\Exceptions\OwnerNotFoundException;
use OCA\Circles\Exceptions\RemoteNotFoundException;
use OCA\Circles\Exceptions\RemoteResourceNotFoundException;
use OCA\Circles\Exceptions\UnknownRemoteException;
use OCA\Circles\FederatedItems\CircleCreate;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Federated\FederatedEvent;
use OCA\Circles\Model\Federated\RemoteInstance;
use OCA\Circles\Model\FederatedUser;
use OCA\Circles\Model\ManagedModel;
use OCA\Circles\Model\Member;


/**
 * Class CircleService
 *
 * @package OCA\Circles\Service
 */
class CircleService {


	use TArrayTools;
	use TStringTools;


	/** @var CircleRequest */
	private $circleRequest;

	/** @var MemberRequest */
	private $memberRequest;

	/** @var RemoteService */
	private $remoteService;

	/** @var FederatedUserService */
	private $federatedUserService;

	/** @var FederatedEventService */
	private $federatedEventService;

	/** @var ConfigService */
	private $configService;


	/**
	 * CircleService constructor.
	 *
	 * @param CircleRequest $circleRequest
	 * @param MemberRequest $memberRequest
	 * @param RemoteService $remoteService
	 * @param FederatedUserService $federatedUserService
	 * @param FederatedEventService $federatedEventService
	 * @param ConfigService $configService
	 */
	public function __construct(
		CircleRequest $circleRequest, MemberRequest $memberRequest, RemoteService $remoteService,
		FederatedUserService $federatedUserService, FederatedEventService $federatedEventService,
		ConfigService $configService
	) {
		$this->circleRequest = $circleRequest;
		$this->memberRequest = $memberRequest;
		$this->remoteService = $remoteService;
		$this->federatedUserService = $federatedUserService;
		$this->federatedEventService = $federatedEventService;
		$this->configService = $configService;
	}


	/**
	 * @param string $name
	 * @param FederatedUser|null $owner
	 *
	 * @return Circle
	 * @throws OwnerNotFoundException
	 * @throws FederatedEventException
	 * @throws InitiatorNotFoundException
	 * @throws InitiatorNotConfirmedException
	 */
	public function create(string $name, ?FederatedUser $owner = null): Circle {
		$this->federatedUserService->mustHaveCurrentUser();
		if (is_null($owner)) {
			$owner = $this->federatedUserService->getCurrentUser();
		}

		$circle = new Circle();
		$circle->setName($name);
		$circle->setId($this->token(ManagedModel::ID_LENGTH));

		$member = new Member();
		$member->importFromIFederatedUser($owner);
		$member->setId($this->token(ManagedModel::ID_LENGTH))
			   ->setCircleId($circle->getId())
			   ->setLevel(Member::LEVEL_OWNER)
			   ->setStatus(Member::STATUS_MEMBER);
		$circle->setOwner($member)
			   ->setInitiator($member);

		$event = new FederatedEvent(CircleCreate::class);
		$event->setCircle($circle);
		$this->federatedEventService->newEvent($event);

		return $circle;
	}


	/**
	 * @param Member|null $filter
	 *
	 * @return Circle[]
	 * @throws InitiatorNotFoundException
	 */
	public function getCircles(?Member $filter = null): array {
		$this->federatedUserService->mustHaveCurrentUser();

		return $this->circleRequest->getCircles($filter, $this->federatedUserService->getCurrentUser());
	}


	/**
	 * @param string $circleId
	 *
	 * @return Circle
	 * @throws CircleNotFoundException
	 * @throws InitiatorNotFoundException
	 */
	public function getCircle(string $circleId): Circle {
		$this->federatedUserService->mustHaveCurrentUser();

		return $this->circleRequest->getCircle(
			$circleId,
			$this->federatedUserService->getCurrentUser(),
			$this->federatedUserService->getRemoteInstance()
		);
	}


	/**
	 * @param Circle $circle
	 *
	 * @throws MembersLimitException
	 */
	public function confirmCircleNotFull(Circle $circle): void {
		if ($this->isCircleFull($circle)) {
			throw new MembersLimitException('circle is full');
		}
	}

	/**
	 * @param Circle $circle
	 *
	 * @return bool
	 */
	public function isCircleFull(Circle $circle): bool {
		$members = $this->memberRequest->getMembers($circle->getId());

		$limit = $this->getInt('members_limit', $circle->getSettings());
		if ($limit === -1) {
			return false;
		}
		if ($limit === 0) {
			$limit = $this->configService->getAppValue(ConfigService::CIRCLES_MEMBERS_LIMIT);
		}

		return (sizeof($members) >= $limit);
	}


	/**
	 * @param Circle $circle
	 *
	 * @throws RemoteNotFoundException
	 * @throws RemoteResourceNotFoundException
	 * @throws RequestNetworkException
	 * @throws SignatoryException
	 * @throws UnknownRemoteException
	 * @throws OwnerNotFoundException
	 */
	public function syncCircle(Circle $circle): void {
//		if ($this->configService->isLocalInstance($circle->getInstance())) {
//			$this->syncLocalCircle($circle);
//		} else {
		$this->syncRemoteCircle($circle->getId(), $circle->getInstance());
//		}
	}

	/**
	 * @param Circle $circle
	 */
	private function syncLocalCircle(Circle $circle): void {

	}

	/**
	 * @param string $circleId
	 * @param string $instance
	 *
	 * @throws InvalidItemException
	 * @throws OwnerNotFoundException
	 * @throws RemoteNotFoundException
	 * @throws RemoteResourceNotFoundException
	 * @throws RequestNetworkException
	 * @throws SignatoryException
	 * @throws UnknownRemoteException
	 */
	public function syncRemoteCircle(string $circleId, string $instance): void {
		while (true) {
			$circle = $this->retrieveCircleFromInstance($circleId, $instance);
			if ($circle->getInstance() === $instance) {
				break;
			}

			$instance = $circle->getInstance();
		}

		echo json_encode($circle);
	}


	/**
	 * @param string $circleId
	 * @param string $instance
	 *
	 * @return Circle
	 * @throws RemoteNotFoundException
	 * @throws RemoteResourceNotFoundException
	 * @throws RequestNetworkException
	 * @throws SignatoryException
	 * @throws UnknownRemoteException
	 * @throws InvalidItemException
	 */
	private function retrieveCircleFromInstance(string $circleId, string $instance): Circle {
		$result = $this->remoteService->resultRequestRemoteInstance(
			$instance,
			RemoteInstance::CIRCLE,
			Request::TYPE_GET,
			null,
			['circleId' => $circleId]
		);

		$circle = new Circle();
		$circle->import($result);

		return $circle;
	}

}

