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


namespace OCA\Circles\Model;

use daita\MySmallPhpTools\Db\Nextcloud\nc22\INC22QueryRow;
use daita\MySmallPhpTools\Exceptions\InvalidItemException;
use daita\MySmallPhpTools\IDeserializable;
use daita\MySmallPhpTools\Traits\Nextcloud\nc22\TNC22Deserialize;
use daita\MySmallPhpTools\Traits\TArrayTools;
use JsonSerializable;
use OCA\Circles\Exceptions\FederatedUserNotFoundException;
use OCA\Circles\Exceptions\OwnerNotFoundException;
use OCA\Circles\IFederatedUser;


/**
 * Class FederatedUser
 *
 * @package OCA\Circles\Model
 */
class FederatedUser extends ManagedModel implements IFederatedUser, IDeserializable, INC22QueryRow, JsonSerializable {


	use TArrayTools;
	use TNC22Deserialize;


	/** @var string */
	private $singleId = '';

	/** @var string */
	private $userId;

	/** @var int */
	private $userType;

	/** @var Circle */
	private $basedOn;

	/** @var int */
	private $config = 0;

	/** @var string */
	private $instance;

	/** @var Membership[] */
	private $memberships = null;


	/**
	 * FederatedUser constructor.
	 */
	public function __construct() {
	}


	/**
	 * @param string $userId
	 * @param string $instance
	 * @param int $type
	 * @param Circle|null $basedOn
	 *
	 * @return $this
	 */
	public function set(
		string $userId = '',
		$instance = '',
		int $type = Member::TYPE_USER,
		?Circle $basedOn = null
	): self {

		$this->userId = $userId;
		$this->setInstance($instance);
		$this->userType = $type;
		$this->basedOn = $basedOn;

		return $this;
	}


	/**
	 * @param string $singleId
	 *
	 * @return self
	 */
	public function setSingleId(string $singleId): self {
		$this->singleId = $singleId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSingleId(): string {
		return $this->singleId;
	}


	/**
	 * @param string $userId
	 *
	 * @return self
	 */
	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}


	/**
	 * @param int $userType
	 *
	 * @return self
	 */
	public function setUserType(int $userType): self {
		$this->userType = $userType;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getUserType(): int {
		return $this->userType;
	}


	/**
	 * @param Circle|null $basedOn
	 *
	 * @return $this
	 */
	public function setBasedOn(?Circle $basedOn): self {
		$this->basedOn = $basedOn;

		return $this;
	}

	/**
	 * @return Circle|null
	 */
	public function getBasedOn(): ?Circle {
		return $this->basedOn;
	}


	/**
	 * @param int $config
	 *
	 * @return self
	 */
	public function setConfig(int $config): self {
		$this->config = $config;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getConfig(): int {
		return $this->config;
	}


	/**
	 * @param string $instance
	 *
	 * @return self
	 */
	public function setInstance(string $instance): self {
		if ($instance === '') {
			$instance = $this->getManager()->getLocalInstance();
		}

		$this->instance = $instance;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getInstance(): string {
		return $this->instance;
	}


	/**
	 * @param Membership[] $memberships
	 *
	 * @return self
	 */
	public function setMemberships(array $memberships): self {
		$this->memberships = $memberships;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getMemberships(): array {
		if ($this->memberships === null) {
			$this->getManager()->getMemberships($this);
		}

		return $this->memberships;
	}


	/**
	 * @param array $data
	 *
	 * @return $this
	 * @throws InvalidItemException
	 */
	public function import(array $data): IDeserializable {
		if ($this->get('user_id', $data) === '') {
			throw new InvalidItemException();
		}

		$this->setSingleId($this->get('id', $data));
		$this->setUserId($this->get('user_id', $data));
		$this->setUserType($this->getInt('user_type', $data));
		$this->setInstance($this->get('instance', $data));
		//$this->setMemberships($this->getArray('memberships'));

		try {
			/** @var Circle $circle */
			$circle = $this->deserialize($this->getArray('basedOn', $data), Circle::class);
			$this->setBasedOn($circle);
		} catch (InvalidItemException $e) {
		}

		return $this;
	}


	/**
	 * @param Circle $circle
	 *
	 * @return FederatedUser
	 * @throws OwnerNotFoundException
	 */
	public function importFromCircle(Circle $circle): self {
		$this->setSingleId($circle->getId());

		if ($circle->isConfig(Circle::CFG_SINGLE)) {
			$owner = $circle->getOwner();
			$this->set($owner->getUserId(), $owner->getInstance(), $owner->getUserType(), $circle);
		} else {
			$this->set($circle->getDisplayName(), $circle->getInstance(), Member::TYPE_CIRCLE, $circle);
		}

		return $this;
	}


	/**
	 * @param array $data
	 * @param string $prefix
	 *
	 * @return INC22QueryRow
	 * @throws FederatedUserNotFoundException
	 */
	public function importFromDatabase(array $data, string $prefix = ''): INC22QueryRow {
		if ($this->get($prefix . 'single_id', $data) === '') {
			throw new FederatedUserNotFoundException();
		}

		$this->setSingleId($this->get($prefix . 'single_id', $data));
		$this->setUserId($this->get($prefix . 'user_id', $data));
		$this->setUserType($this->getInt($prefix . 'user_type', $data));
		$this->setInstance($this->get($prefix . 'instance', $data));

		$this->getManager()->manageImportFromDatabase($this, $data, $prefix);

		return $this;
	}


	/**
	 * @return string[]
	 */
	public function jsonSerialize(): array {
		$arr = [
			'id'        => $this->getSingleId(),
			'user_id'   => $this->getUserId(),
			'user_type' => $this->getUserType(),
			'instance'  => $this->getInstance()
		];

		if (!is_null($this->getBasedOn())) {
			$arr['basedOn'] = $this->getBasedOn();
		}

		if (!is_null($this->memberships)) {
			$arr['memberships'] = $this->getMemberships();
		}

		return $arr;
	}


	/**
	 * @param IFederatedUser $member
	 *
	 * @return bool
	 */
	public function compareWith(IFederatedUser $member): bool {
		return !($this->getSingleId() !== $member->getSingleId()
				 || $this->getUserId() !== $member->getUserId()
				 || $this->getUserType() <> $member->getUserType()
				 || $this->getInstance() !== $member->getInstance());
	}

}

