<?php

/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
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


use Doctrine\DBAL\Query\QueryBuilder;
use OC\L10N\L10N;
use OCA\Circles\Exceptions\ConfigNoCircleAvailableException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\FederatedLink;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\SharingFrame;
use OCA\Circles\Service\ConfigService;
use OCA\Circles\Service\MiscService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CirclesRequestBuilder extends CoreRequestBuilder {


	/** @var MembersRequest */
	protected $membersRequest;

	/**
	 * CirclesRequestBuilder constructor.
	 *
	 * {@inheritdoc}
	 * @param MembersRequest $membersRequest
	 */
	public function __construct(
		L10N $l10n, IDBConnection $connection, MembersRequest $membersRequest,
		ConfigService $configService, MiscService $miscService
	) {
		parent::__construct($l10n, $connection, $configService, $miscService);
		$this->membersRequest = $membersRequest;
	}


	/**
	 * Left Join the Groups table
	 *
	 * @param IQueryBuilder $qb
	 * @param string $field
	 */
	protected function leftJoinGroups(IQueryBuilder &$qb, $field) {
		$expr = $qb->expr();

		$qb->leftJoin(
			$this->default_select_alias, CoreRequestBuilder::TABLE_GROUPS, 'g',
			$expr->eq($field, 'g.circle_id')
		);
	}

	/**
	 * Limit the search to a non-personal circle
	 *
	 * @param IQueryBuilder $qb
	 */
	protected function limitToNonPersonalCircle(IQueryBuilder &$qb) {
		$expr = $qb->expr();

		$qb->andWhere(
			$expr->neq('c.type', $qb->createNamedParameter(Circle::CIRCLES_PERSONAL))
		);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $circleUniqueId
	 * @param $userId
	 * @param $type
	 * @param $name
	 *
	 * @throws ConfigNoCircleAvailableException
	 */
	protected function limitRegardingCircleType(
		IQueryBuilder &$qb, $userId, $circleUniqueId, $type, $name
	) {
		$orTypes = $this->generateLimit($qb, $circleUniqueId, $userId, $type, $name);
		if (sizeof($orTypes) === 0) {
			throw new ConfigNoCircleAvailableException(
				$this->l10n->t(
					'You cannot use the Circles Application until your administrator has allowed at least one type of circles'
				)
			);
		}

		$orXTypes = $qb->expr()
					   ->orX();
		foreach ($orTypes as $orType) {
			$orXTypes->add($orType);
		}

		$qb->andWhere($orXTypes);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $circleUniqueId
	 * @param $userId
	 * @param $type
	 * @param $name
	 *
	 * @return array
	 */
	private function generateLimit(IQueryBuilder &$qb, $circleUniqueId, $userId, $type, $name) {
		$orTypes = [];
		array_push($orTypes, $this->generateLimitPersonal($qb, $userId, $type));
		array_push($orTypes, $this->generateLimitHidden($qb, $circleUniqueId, $type, $name));
		array_push($orTypes, $this->generateLimitPrivate($qb, $type));
		array_push($orTypes, $this->generateLimitPublic($qb, $type));

		return array_filter($orTypes);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int|string $userId
	 * @param int $type
	 *
	 * @return \OCP\DB\QueryBuilder\ICompositeExpression
	 */
	private function generateLimitPersonal(IQueryBuilder $qb, $userId, $type) {
		if (!(Circle::CIRCLES_PERSONAL & (int)$type)) {
			return null;
		}
		$expr = $qb->expr();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		return $expr->andX(
			$expr->eq('c.type', $qb->createNamedParameter(Circle::CIRCLES_PERSONAL)),
			$expr->eq('o.user_id', $qb->createNamedParameter((string)$userId))
		);
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param string $circleUniqueId
	 * @param int $type
	 * @param string $name
	 *
	 * @return string
	 */
	private function generateLimitHidden(IQueryBuilder $qb, $circleUniqueId, $type, $name) {
		if (!(Circle::CIRCLES_HIDDEN & (int)$type)) {
			return null;
		}
		$expr = $qb->expr();

		$orX = $expr->orX($expr->gte('u.level', $qb->createNamedParameter(Member::LEVEL_MEMBER)));
		$orX->add($expr->eq('c.name', $qb->createNamedParameter($name)))
			->add(
				$expr->eq(
					$qb->createNamedParameter($circleUniqueId),
					$qb->createFunction('LEFT(c.unique_id, ' . Circle::UNIQUEID_SHORT_LENGTH . ')')
				)
			);

		if ($this->leftJoinedNCGroupAndUser) {
			$orX->add($expr->gte('g.level', $qb->createNamedParameter(Member::LEVEL_MEMBER)));
		}

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$sqb = $expr->andX(
			$expr->eq('c.type', $qb->createNamedParameter(Circle::CIRCLES_HIDDEN)),
			$expr->orX($orX)
		);

		return $sqb;
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int $type
	 *
	 * @return string
	 */
	private function generateLimitPrivate(IQueryBuilder $qb, $type) {
		if (!(Circle::CIRCLES_PRIVATE & (int)$type)) {
			return null;
		}

		return $qb->expr()
				  ->eq(
					  'c.type',
					  $qb->createNamedParameter(Circle::CIRCLES_PRIVATE)
				  );
	}


	/**
	 * @param IQueryBuilder $qb
	 * @param int $type
	 *
	 * @return string
	 */
	private function generateLimitPublic(IQueryBuilder $qb, $type) {
		if (!(Circle::CIRCLES_PUBLIC & (int)$type)) {
			return null;
		}

		return $qb->expr()
				  ->eq(
					  'c.type',
					  $qb->createNamedParameter(Circle::CIRCLES_PUBLIC)
				  );
	}


	/**
	 * add a request to the members list, using the current user ID.
	 * will returns level and stuff.
	 *
	 * @param IQueryBuilder $qb
	 * @param string $userId
	 */
	protected function leftJoinUserIdAsViewer(IQueryBuilder &$qb, $userId) {

		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$pf = $this->default_select_alias . '.';

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectAlias('u.user_id', 'viewer_userid')
		   ->selectAlias('u.status', 'viewer_status')
		   ->selectAlias('u.level', 'viewer_level')
		   ->leftJoin(
			   $this->default_select_alias, CoreRequestBuilder::TABLE_MEMBERS, 'u',
			   $expr->andX(
				   $expr->eq(
					   'u.circle_id',
					   $qb->createFunction(
						   'LEFT(' . $pf . 'unique_id, ' . Circle::UNIQUEID_SHORT_LENGTH . ')'
					   )
				   ),
				   $expr->eq('u.user_id', $qb->createNamedParameter($userId))
			   )
		   );
	}

	/**
	 * Left Join members table to get the owner of the circle.
	 *
	 * @param IQueryBuilder $qb
	 */
	protected function leftJoinOwner(IQueryBuilder &$qb) {

		if ($qb->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $qb->expr();
		$pf = $this->default_select_alias . '.';

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectAlias('o.user_id', 'owner_userid')
		   ->selectAlias('o.status', 'owner_status')
		   ->selectAlias('o.level', 'owner_level')
		   ->leftJoin(
			   $this->default_select_alias, CoreRequestBuilder::TABLE_MEMBERS, 'o',
			   $expr->andX(
				   $expr->eq(
					   $qb->createFunction(
						   'LEFT(' . $pf . 'unique_id, ' . Circle::UNIQUEID_SHORT_LENGTH . ')'
					   )
					   , 'o.circle_id'
				   ),
				   $expr->eq('o.level', $qb->createNamedParameter(Member::LEVEL_OWNER))
			   )
		   );
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getLinksSelectSql() {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('id', 'status', 'address', 'token', 'circle_id', 'unique_id', 'creation')
		   ->from(self::TABLE_LINKS, 's');

		$this->default_select_alias = 's';

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getSharesSelectSql() {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select(
			'circle_id', 'source', 'type', 'author', 'cloud_id', 'payload', 'creation', 'headers',
			'unique_id'
		)
		   ->from(self::TABLE_SHARES, 's');

		$this->default_select_alias = 's';

		return $qb;
	}

	/**
	 * Base of the Sql Insert request for Shares
	 *
	 * @return IQueryBuilder
	 */
	protected function getSharesInsertSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_SHARES)
		   ->setValue('creation', $qb->createFunction('NOW()'));

		return $qb;
	}


	/**
	 * Base of the Sql Update request for Shares
	 *
	 * @param string $uniqueId
	 *
	 * @return IQueryBuilder
	 */
	protected function getSharesUpdateSql($uniqueId) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_SHARES)
		   ->where(
			   $qb->expr()
				  ->eq('unique_id', $qb->createNamedParameter((string)$uniqueId))
		   );

		return $qb;
	}


	/**
	 * Base of the Sql Insert request for Shares
	 *
	 *
	 * @return IQueryBuilder
	 */
	protected function getCirclesInsertSql() {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->insert(self::TABLE_CIRCLES)
		   ->setValue('creation', $qb->createFunction('NOW()'));

		return $qb;
	}


	/**
	 * Base of the Sql Update request for Shares
	 *
	 * @param int $uniqueId
	 *
	 * @return IQueryBuilder
	 */
	protected function getCirclesUpdateSql($uniqueId) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->update(self::TABLE_CIRCLES)
		   ->where(
			   $qb->expr()
				  ->eq('unique_id', $qb->createNamedParameter($uniqueId))
		   );

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @param string $circleUniqueId
	 *
	 * @return IQueryBuilder
	 */
	protected function getCirclesDeleteSql($circleUniqueId) {
		$qb = $this->dbConnection->getQueryBuilder();
		$qb->delete(self::TABLE_CIRCLES)
		   ->where(
			   $qb->expr()
				  ->eq(
					  $qb->createFunction('LEFT(unique_id, ' . Circle::UNIQUEID_SHORT_LENGTH),
					  $qb->createNamedParameter($circleUniqueId)
				  )
		   );

		return $qb;
	}


	/**
	 * @return IQueryBuilder
	 */
	protected function getCirclesSelectSql() {
		$qb = $this->dbConnection->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->selectDistinct('c.unique_id')
		   ->addSelect(
			   'c.id', 'c.name', 'c.description', 'c.settings', 'c.type', 'c.creation'
		   )
		   ->from(CoreRequestBuilder::TABLE_CIRCLES, 'c');
		$this->default_select_alias = 'c';

		return $qb;
	}


	/**
	 * @param array $data
	 *
	 * @return Circle
	 */
	protected function parseCirclesSelectSql($data) {

		$circle = new Circle($this->l10n);
		$circle->setId($data['id']);
		$circle->setUniqueId($data['unique_id']);
		$circle->setName($data['name']);
		$circle->setDescription($data['description']);
		$circle->setSettings($data['settings']);
		$circle->setType($data['type']);
		$circle->setCreation($data['creation']);

		if (key_exists('viewer_level', $data)) {
			$user = new Member($this->l10n);
			$user->setStatus($data['viewer_status']);
			$user->setCircleId($circle->getUniqueId());
			$user->setUserId($data['viewer_userid']);
			$user->setLevel($data['viewer_level']);
			$circle->setViewer($user);
		}

		if (key_exists('owner_level', $data)) {
			$owner = new Member($this->l10n);
			$owner->setStatus($data['owner_status']);
			$owner->setCircleId($circle->getUniqueId());
			$owner->setUserId($data['owner_userid']);
			$owner->setLevel($data['owner_level']);
			$circle->setOwner($owner);
		}

		return $circle;
	}


	/**
	 * @param array $data
	 *
	 * @return SharingFrame
	 */
	protected function parseSharesSelectSql($data) {
		$frame = new SharingFrame($data['source'], $data['type']);
		$frame->setCircleId($data['circle_id']);
		$frame->setAuthor($data['author']);
		$frame->setCloudId($data['cloud_id']);
		$frame->setPayload(json_decode($data['payload'], true));
		$frame->setCreation($data['creation']);
		$frame->setHeaders(json_decode($data['headers'], true));
		$frame->setUniqueId($data['unique_id']);

		return $frame;
	}


	/**
	 * @param array $data
	 *
	 * @return FederatedLink
	 */
	protected function parseLinksSelectSql($data) {
		$link = new FederatedLink();
		$link->setId($data['id'])
			 ->setUniqueId($data['unique_id'])
			 ->setStatus($data['status'])
			 ->setCreation($data['creation'])
			 ->setAddress($data['address'])
			 ->setToken($data['token'])
			 ->setCircleId($data['circle_id']);

		return $link;
	}


}