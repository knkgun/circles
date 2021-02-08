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


namespace OCA\Circles\Db;


use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Exceptions\InvalidIdException;
use OCA\Circles\IFederatedUser;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Federated\RemoteInstance;
use OCA\Circles\Model\Member;


/**
 * Class CircleRequest
 *
 * @package OCA\Circles\Db
 */
class CircleRequest extends CircleRequestBuilder {


	/**
	 * @param Circle $circle
	 *
	 * @throws InvalidIdException
	 */
	public function save(Circle $circle): void {
		$this->confirmValidId($circle->getId());

		$qb = $this->getCircleInsertSql();
		$qb->setValue('unique_id', $qb->createNamedParameter($circle->getId()))
		   ->setValue('long_id', $qb->createNamedParameter($circle->getId()))
		   ->setValue('name', $qb->createNamedParameter($circle->getName()))
		   ->setValue('alt_name', $qb->createNamedParameter($circle->getAltName()))
		   ->setValue('description', $qb->createNamedParameter($circle->getDescription()))
		   ->setValue('contact_addressbook', $qb->createNamedParameter($circle->getContactAddressBook()))
		   ->setValue('contact_groupname', $qb->createNamedParameter($circle->getContactGroupName()))
		   ->setValue('settings', $qb->createNamedParameter(json_encode($circle->getSettings())))
		   ->setValue('type', $qb->createNamedParameter(0))
		   ->setValue('config', $qb->createNamedParameter($circle->getConfig()));

		$qb->execute();
	}


	/**
	 * @param Circle $circle
	 */
	public function update(Circle $circle) {
		$qb = $this->getCircleUpdateSql();
//		$qb->set('uid', $qb->createNamedParameter($circle->getUid(true)))
//		   ->set('href', $qb->createNamedParameter($circle->getId()))
//		   ->set('item', $qb->createNamedParameter(json_encode($circle->getOrigData())));

		$qb->limitToUniqueId($circle->getId());

		$qb->execute();
	}


	/**
	 * @param Member|null $filter
	 * @param IFederatedUser|null $initiator
	 *
	 * @return Circle[]
	 */
	public function getCircles(?Member $filter = null, ?IFederatedUser $initiator = null): array {
		$qb = $this->getCircleSelectSql();
		$qb->leftJoinOwner();

		if (!is_null($initiator)) {
			$qb->limitToInitiator($initiator);
		}

		if (!is_null($filter)) {
			$qb->limitToMembership($filter, $filter->getLevel());
		}

		return $this->getItemsFromRequest($qb);
	}


	/**
	 * @param string $id
	 * @param IFederatedUser|null $initiator
	 * @param RemoteInstance|null $instance
	 *
	 * @return Circle
	 * @throws CircleNotFoundException
	 */
	public function getCircle(
		string $id,
		?IFederatedUser $initiator = null,
		?RemoteInstance $instance = null
	): Circle {
		$qb = $this->getCircleSelectSql();
		$qb->limitToUniqueId($id);
		$qb->leftJoinOwner();

		if (!is_null($initiator)) {
			$qb->limitToInitiator($initiator);
		} else if (!is_null($instance)) {
			// TODO: filter based on RemoteInstance $instance
		}

		return $this->getItemFromRequest($qb);
	}


	/**
	 * method that return the single-user Circle based on a Viewer.
	 *
	 * @param IFederatedUser $initiator
	 *
	 * @return Circle
	 * @throws CircleNotFoundException
	 */
	public function getInitiatorCircle(IFederatedUser $initiator): Circle {
		$qb = $this->getCircleSelectSql();
		$qb->leftJoinOwner();
		$qb->limitToMembership($initiator, Member::LEVEL_OWNER);
		$qb->limitToConfig(Circle::CFG_SINGLE);

		return $this->getItemFromRequest($qb);
	}


	/**
	 * @return Circle[]
	 */
	public function getFederated(): array {
		$qb = $this->getCircleSelectSql();
		$qb->filterConfig(Circle::CFG_FEDERATED);
		$qb->leftJoinOwner();

		return $this->getItemsFromRequest($qb);
	}


}

