<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Joas Schilling <coding@schilljs.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Talk\Service;

use OCA\Talk\Config;
use OCA\Talk\Events\AddParticipantsEvent;
use OCA\Talk\Events\JoinRoomGuestEvent;
use OCA\Talk\Events\JoinRoomUserEvent;
use OCA\Talk\Events\ModifyParticipantEvent;
use OCA\Talk\Events\ParticipantEvent;
use OCA\Talk\Events\RemoveParticipantEvent;
use OCA\Talk\Events\RemoveUserEvent;
use OCA\Talk\Events\RoomEvent;
use OCA\Talk\Exceptions\InvalidPasswordException;
use OCA\Talk\Exceptions\ParticipantNotFoundException;
use OCA\Talk\Exceptions\UnauthorizedException;
use OCA\Talk\Model\Attendee;
use OCA\Talk\Model\AttendeeMapper;
use OCA\Talk\Model\SelectHelper;
use OCA\Talk\Model\Session;
use OCA\Talk\Model\SessionMapper;
use OCA\Talk\Participant;
use OCA\Talk\Room;
use OCA\Talk\Webinary;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Security\ISecureRandom;

class ParticipantService {
	/** @var IConfig */
	protected $serverConfig;
	/** @var Config */
	protected $talkConfig;
	/** @var AttendeeMapper */
	protected $attendeeMapper;
	/** @var SessionMapper */
	protected $sessionMapper;
	/** @var SessionService */
	protected $sessionService;
	/** @var ISecureRandom */
	private $secureRandom;
	/** @var IDBConnection */
	protected $connection;
	/** @var IEventDispatcher */
	private $dispatcher;
	/** @var IUserManager */
	private $userManager;
	/** @var IGroupManager */
	private $groupManager;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(IConfig $serverConfig,
								Config $talkConfig,
								AttendeeMapper $attendeeMapper,
								SessionMapper $sessionMapper,
								SessionService $sessionService,
								ISecureRandom $secureRandom,
								IDBConnection $connection,
								IEventDispatcher $dispatcher,
								IUserManager $userManager,
								IGroupManager $groupManager,
								ITimeFactory $timeFactory) {
		$this->serverConfig = $serverConfig;
		$this->talkConfig = $talkConfig;
		$this->attendeeMapper = $attendeeMapper;
		$this->sessionMapper = $sessionMapper;
		$this->sessionService = $sessionService;
		$this->secureRandom = $secureRandom;
		$this->connection = $connection;
		$this->dispatcher = $dispatcher;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->timeFactory = $timeFactory;
	}

	public function updateParticipantType(Room $room, Participant $participant, int $participantType): void {
		$attendee = $participant->getAttendee();
		$oldType = $attendee->getParticipantType();

		$event = new ModifyParticipantEvent($room, $participant, 'type', $participantType, $oldType);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_TYPE_SET, $event);

		$attendee->setParticipantType($participantType);
		$this->attendeeMapper->update($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_TYPE_SET, $event);
	}

	public function updateLastReadMessage(Participant $participant, int $lastReadMessage): void {
		$attendee = $participant->getAttendee();
		$attendee->setLastReadMessage($lastReadMessage);
		$this->attendeeMapper->update($attendee);
	}

	public function updateFavoriteStatus(Participant $participant, bool $isFavorite): void {
		$attendee = $participant->getAttendee();
		$attendee->setFavorite($isFavorite);
		$this->attendeeMapper->update($attendee);
	}

	/**
	 * @param Participant $participant
	 * @param int $level
	 * @throws \InvalidArgumentException When the notification level is invalid
	 */
	public function updateNotificationLevel(Participant $participant, int $level): void {
		if (!\in_array($level, [
			Participant::NOTIFY_ALWAYS,
			Participant::NOTIFY_MENTION,
			Participant::NOTIFY_NEVER
		], true)) {
			throw new \InvalidArgumentException('Invalid notification level');
		}

		$attendee = $participant->getAttendee();
		$attendee->setNotificationLevel($level);
		$this->attendeeMapper->update($attendee);
	}

	/**
	 * @param Room $room
	 * @param IUser $user
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @return Participant
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoom(Room $room, IUser $user, string $password, bool $passedPasswordProtection = false): Participant {
		$event = new JoinRoomUserEvent($room, $user, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_ROOM_CONNECT, $event);

		if ($event->getCancelJoin() === true) {
			$this->removeUser($room, $user, Room::PARTICIPANT_LEFT);
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		try {
			$attendee = $this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_USERS, $user->getUID());
		} catch (DoesNotExistException $e) {
			if (!$event->getPassedPasswordProtection() && !$room->verifyPassword($password)['result']) {
				throw new InvalidPasswordException('Provided password is invalid');
			}

			// queried here to avoid loop deps
			$manager = \OC::$server->get(\OCA\Talk\Manager::class);

			// User joining a group or public call through listing
			if (($room->getType() === Room::GROUP_CALL || $room->getType() === Room::PUBLIC_CALL) &&
				$manager->isRoomListableByUser($room, $user->getUID())
			) {
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $user->getUID(),
					// need to use "USER" here, because "USER_SELF_JOINED" only works for public calls
					'participantType' => Participant::USER,
				]]);
			} elseif ($room->getType() === Room::PUBLIC_CALL) {
				// User joining a public room, without being invited
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $user->getUID(),
					'participantType' => Participant::USER_SELF_JOINED,
				]]);
			} else {
				// shouldn't happen unless some code called joinRoom without previous checks
				throw new UnauthorizedException('Participant is not allowed to join');
			}

			$attendee = $this->attendeeMapper->findByActor($room->getId(), Attendee::ACTOR_USERS, $user->getUID());
		}

		$session = $this->sessionService->createSessionForAttendee($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_ROOM_CONNECT, $event);

		return new Participant($room, $attendee, $session);
	}

	/**
	 * @param Room $room
	 * @param string $password
	 * @param bool $passedPasswordProtection
	 * @return Participant
	 * @throws InvalidPasswordException
	 * @throws UnauthorizedException
	 */
	public function joinRoomAsNewGuest(Room $room, string $password, bool $passedPasswordProtection = false): Participant {
		$event = new JoinRoomGuestEvent($room, $password, $passedPasswordProtection);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_GUEST_CONNECT, $event);

		if ($event->getCancelJoin()) {
			throw new UnauthorizedException('Participant is not allowed to join');
		}

		if (!$event->getPassedPasswordProtection() && !$room->verifyPassword($password)['result']) {
			throw new InvalidPasswordException();
		}

		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		$randomActorId = $this->secureRandom->generate(255);

		$attendee = new Attendee();
		$attendee->setRoomId($room->getId());
		$attendee->setActorType(Attendee::ACTOR_GUESTS);
		$attendee->setActorId($randomActorId);
		$attendee->setParticipantType(Participant::GUEST);
		$attendee->setLastReadMessage($lastMessage);
		$this->attendeeMapper->insert($attendee);

		$session = $this->sessionService->createSessionForAttendee($attendee);

		// Update the random guest id
		$attendee->setActorId(sha1($session->getSessionId()));
		$this->attendeeMapper->update($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_GUEST_CONNECT, $event);

		return new Participant($room, $attendee, $session);
	}

	/**
	 * @param Room $room
	 * @param array $participants
	 */
	public function addUsers(Room $room, array $participants): void {
		$event = new AddParticipantsEvent($room, $participants);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_USERS_ADD, $event);

		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		foreach ($participants as $participant) {
			$readPrivacy = Participant::PRIVACY_PUBLIC;
			if ($participant['actorType'] === Attendee::ACTOR_USERS) {
				$readPrivacy = $this->talkConfig->getUserReadPrivacy($participant['actorId']);
			}

			$attendee = new Attendee();
			$attendee->setRoomId($room->getId());
			$attendee->setActorType($participant['actorType']);
			$attendee->setActorId($participant['actorId']);
			$attendee->setParticipantType($participant['participantType'] ?? Participant::USER);
			$attendee->setLastReadMessage($lastMessage);
			$attendee->setReadPrivacy($readPrivacy);
			$this->attendeeMapper->insert($attendee);
		}

		$this->dispatcher->dispatch(Room::EVENT_AFTER_USERS_ADD, $event);
	}

	/**
	 * @param Room $room
	 * @param string $email
	 * @return Participant
	 */
	public function inviteEmailAddress(Room $room, string $email): Participant {
		$lastMessage = 0;
		if ($room->getLastMessage() instanceof IComment) {
			$lastMessage = (int) $room->getLastMessage()->getId();
		}

		$attendee = new Attendee();
		$attendee->setRoomId($room->getId());
		$attendee->setActorType(Attendee::ACTOR_EMAILS);
		$attendee->setActorId($email);

		if ($room->getSIPEnabled() === Webinary::SIP_ENABLED
			&& $this->talkConfig->isSIPConfigured()) {
			$attendee->setPin($this->generatePin());
		}

		$attendee->setParticipantType(Participant::GUEST);
		$attendee->setLastReadMessage($lastMessage);
		$this->attendeeMapper->insert($attendee);
		// FIXME handle duplicate invites gracefully

		return new Participant($room, $attendee, null);
	}

	public function generatePinForParticipant(Room $room, Participant $participant): void {
		$attendee = $participant->getAttendee();
		if ($room->getSIPEnabled() === Webinary::SIP_ENABLED
			&& $this->talkConfig->isSIPConfigured()
			&& $attendee->getActorType() === Attendee::ACTOR_USERS
			&& !$attendee->getPin()) {
			$attendee->setPin($this->generatePin());
			$this->attendeeMapper->update($attendee);
		}
	}

	public function ensureOneToOneRoomIsFilled(Room $room): void {
		if ($room->getType() !== Room::ONE_TO_ONE_CALL) {
			return;
		}

		$users = json_decode($room->getName(), true);
		$participants = $this->getParticipantUserIds($room);
		$missingUsers = array_diff($users, $participants);

		foreach ($missingUsers as $userId) {
			if ($this->userManager->userExists($userId)) {
				$this->addUsers($room, [[
					'actorType' => Attendee::ACTOR_USERS,
					'actorId' => $userId,
					'participantType' => Participant::OWNER,
				]]);
			}
		}
	}

	public function leaveRoomAsSession(Room $room, Participant $participant): void {
		if ($participant->getAttendee()->getActorType() !== Attendee::ACTOR_GUESTS) {
			$event = new ParticipantEvent($room, $participant);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_ROOM_DISCONNECT, $event);
		} else {
			$event = new RemoveParticipantEvent($room, $participant, Room::PARTICIPANT_LEFT);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_REMOVE, $event);
		}

		$session = $participant->getSession();
		if ($session instanceof Session) {
			$dispatchLeaveCallEvents = $session->getInCall() !== Participant::FLAG_DISCONNECTED;
			if ($dispatchLeaveCallEvents) {
				$event = new ModifyParticipantEvent($room, $participant, 'inCall', Participant::FLAG_DISCONNECTED, $session->getInCall());
				$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_LEAVE_CALL, $event);
			}

			$this->sessionMapper->delete($session);

			if ($dispatchLeaveCallEvents) {
				$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $event);
			}
		} else {
			$this->sessionMapper->deleteByAttendeeId($participant->getAttendee()->getId());
		}

		if ($participant->getAttendee()->getActorType() === Attendee::ACTOR_GUESTS
			|| $participant->getAttendee()->getParticipantType() === Participant::USER_SELF_JOINED) {
			$this->attendeeMapper->delete($participant->getAttendee());
		}

		if ($participant->getAttendee()->getActorType() !== Attendee::ACTOR_GUESTS) {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_ROOM_DISCONNECT, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_REMOVE, $event);
		}
	}

	public function removeAttendee(Room $room, Participant $participant, string $reason): void {
		$isUser = $participant->getAttendee()->getActorType() === Attendee::ACTOR_USERS;

		if ($isUser) {
			$user = $this->userManager->get($participant->getAttendee()->getActorId());
			$event = new RemoveUserEvent($room, $participant, $user, $reason);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_USER_REMOVE, $event);
		} else {
			$event = new RemoveParticipantEvent($room, $participant, $reason);
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_PARTICIPANT_REMOVE, $event);
		}

		$this->sessionMapper->deleteByAttendeeId($participant->getAttendee()->getId());
		$this->attendeeMapper->delete($participant->getAttendee());

		if ($isUser) {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_USER_REMOVE, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_PARTICIPANT_REMOVE, $event);
		}
	}

	public function removeUser(Room $room, IUser $user, string $reason): void {
		try {
			$participant = $room->getParticipant($user->getUID());
		} catch (ParticipantNotFoundException $e) {
			return;
		}

		$event = new RemoveUserEvent($room, $participant, $user, $reason);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_USER_REMOVE, $event);

		$session = $participant->getSession();
		if ($session instanceof Session) {
			$this->sessionMapper->delete($session);
		}

		$attendee = $participant->getAttendee();
		$this->attendeeMapper->delete($attendee);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_USER_REMOVE, $event);
	}

	public function cleanGuestParticipants(Room $room): void {
		$event = new RoomEvent($room);
		$this->dispatcher->dispatch(Room::EVENT_BEFORE_GUESTS_CLEAN, $event);

		$query = $this->connection->getQueryBuilder();
		$query->selectAlias('s.id', 's_id')
			->from('talk_sessions', 's')
			->leftJoin('s', 'talk_attendees', 'a', $query->expr()->eq('s.attendee_id', 'a.id'))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_GUESTS)))
			->andWhere($query->expr()->lte('s.last_ping', $query->createNamedParameter($this->timeFactory->getTime() - 100, IQueryBuilder::PARAM_INT)));

		$sessionTableIds = [];
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$sessionTableIds[] = (int) $row['s_id'];
		}
		$result->closeCursor();

		$this->sessionService->deleteSessionsById($sessionTableIds);

		$query = $this->connection->getQueryBuilder();
		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq('s.attendee_id', 'a.id'))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_GUESTS)))
			->andWhere($query->expr()->isNull('s.id'));

		$attendeeIds = [];
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$attendeeIds[] = (int) $row['a_id'];
		}
		$result->closeCursor();

		$this->attendeeMapper->deleteByIds($attendeeIds);

		$this->dispatcher->dispatch(Room::EVENT_AFTER_GUESTS_CLEAN, $event);
	}

	public function changeInCall(Room $room, Participant $participant, int $flags): void {
		$session = $participant->getSession();
		if (!$session instanceof Session) {
			return;
		}

		$event = new ModifyParticipantEvent($room, $participant, 'inCall', $flags, $session->getInCall());
		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_JOIN_CALL, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_BEFORE_SESSION_LEAVE_CALL, $event);
		}

		$session->setInCall($flags);
		$this->sessionMapper->update($session);

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$attendee = $participant->getAttendee();
			$attendee->setLastJoinedCall($this->timeFactory->getTime());
			$this->attendeeMapper->update($attendee);
		}

		if ($flags !== Participant::FLAG_DISCONNECTED) {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_JOIN_CALL, $event);
		} else {
			$this->dispatcher->dispatch(Room::EVENT_AFTER_SESSION_LEAVE_CALL, $event);
		}
	}

	public function markUsersAsMentioned(Room $room, array $userIds, int $messageId): void {
		$query = $this->connection->getQueryBuilder();
		$query->update('talk_attendees')
			->set('last_mention_message', $query->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->in('actor_id', $query->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)));
		$query->execute();
	}

	public function updateReadPrivacyForActor(string $actorType, string $actorId, int $readPrivacy): void {
		$query = $this->connection->getQueryBuilder();
		$query->update('talk_attendees')
			->set('read_privacy', $query->createNamedParameter($readPrivacy, IQueryBuilder::PARAM_INT))
			->where($query->expr()->eq('actor_type', $query->createNamedParameter($actorType)))
			->andWhere($query->expr()->eq('actor_id', $query->createNamedParameter($actorId)));
		$query->execute();
	}

	public function getLastCommonReadChatMessage(Room $room): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->func()->min('last_read_message'), 'last_common_read_message')
			->from('talk_attendees')
			->where($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->eq('room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('read_privacy', $query->createNamedParameter(Participant::PRIVACY_PUBLIC, IQueryBuilder::PARAM_INT)));

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) ($row['last_common_read_message'] ?? 0);
	}

	/**
	 * @param int[] $roomIds
	 * @return array A map of roomId => "last common read message id"
	 * @psalm-return  array<int, int>
	 */
	public function getLastCommonReadChatMessageForMultipleRooms(array $roomIds): array {
		$query = $this->connection->getQueryBuilder();
		$query->select('room_id')
			->selectAlias($query->func()->min('last_read_message'), 'last_common_read_message')
			->from('talk_attendees')
			->where($query->expr()->eq('actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->in('room_id', $query->createNamedParameter($roomIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($query->expr()->eq('read_privacy', $query->createNamedParameter(Participant::PRIVACY_PUBLIC, IQueryBuilder::PARAM_INT)))
			->groupBy('room_id');

		$commonReads = array_fill_keys($roomIds, 0);
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$commonReads[(int) $row['room_id']] = (int) $row['last_common_read_message'];
		}
		$result->closeCursor();

		return $commonReads;
	}

	/**
	 * @param Room $room
	 * @return Participant[]
	 */
	public function getParticipantsForRoom(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @return Participant[]
	 */
	public function getParticipantsWithSession(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('s.id'));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @return Participant[]
	 */
	public function getParticipantsInCall(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_sessions', 's')
			->leftJoin(
				's', 'talk_attendees', 'a',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param Room $room
	 * @param int $notificationLevel
	 * @return Participant[]
	 */
	public function getParticipantsByNotificationLevel(Room $room, int $notificationLevel): array {
		$query = $this->connection->getQueryBuilder();

		$helper = new SelectHelper();
		$helper->selectAttendeesTable($query);
		$helper->selectSessionsTable($query);
		$query->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.notification_level', $query->createNamedParameter($notificationLevel, IQueryBuilder::PARAM_INT)));

		return $this->getParticipantsFromQuery($query, $room);
	}

	/**
	 * @param IQueryBuilder $query
	 * @return Participant[]
	 */
	protected function getParticipantsFromQuery(IQueryBuilder $query, Room $room): array {
		$participants = [];
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$attendee = $this->attendeeMapper->createAttendeeFromRow($row);
			if (isset($row['s_id'])) {
				$session = $this->sessionMapper->createSessionFromRow($row);
			} else {
				$session = null;
			}

			$participants[] = new Participant($room, $attendee, $session);
		}
		$result->closeCursor();

		return $participants;
	}

	/**
	 * @param Room $room
	 * @param \DateTime|null $maxLastJoined
	 * @return string[]
	 */
	public function getParticipantUserIds(Room $room, \DateTime $maxLastJoined = null): array {
		$maxLastJoinedTimestamp = null;
		if ($maxLastJoined !== null) {
			$maxLastJoinedTimestamp = $maxLastJoined->getTimestamp();
		}
		$attendees = $this->attendeeMapper->getActorsByType($room->getId(), Attendee::ACTOR_USERS, $maxLastJoinedTimestamp);

		return array_map(static function (Attendee $attendee) {
			return $attendee->getActorId();
		}, $attendees);
	}

	/**
	 * @param Room $room
	 * @return string[]
	 */
	public function getParticipantUserIdsNotInCall(Room $room): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('a.actor_id')
			->from('talk_attendees', 'a')
			->leftJoin(
				'a', 'talk_sessions', 's',
				$query->expr()->eq('s.attendee_id', 'a.id')
			)
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter(Attendee::ACTOR_USERS)))
			->andWhere($query->expr()->orX(
				$query->expr()->eq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)),
				$query->expr()->isNull('s.in_call')
			));

		$userIds = [];
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$userIds[] = $row['actor_id'];
		}
		$result->closeCursor();

		return $userIds;
	}

	/**
	 * @param Room $room
	 * @return int
	 */
	public function getNumberOfUsers(Room $room): int {
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), [
			Participant::USER,
			Participant::MODERATOR,
			Participant::OWNER,
		]);
	}

	/**
	 * @param Room $room
	 * @param bool $ignoreGuestModerators
	 * @return int
	 */
	public function getNumberOfModerators(Room $room, bool $ignoreGuestModerators = true): int {
		$participantTypes = [
			Participant::MODERATOR,
			Participant::OWNER,
		];
		if (!$ignoreGuestModerators) {
			$participantTypes[] = Participant::GUEST_MODERATOR;
		}
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), $participantTypes);
	}

	/**
	 * @param Room $room
	 * @return int
	 */
	public function getNumberOfActors(Room $room): int {
		return $this->attendeeMapper->countActorsByParticipantType($room->getId(), []);
	}

	/**
	 * @param Room $room
	 * @return bool
	 */
	public function hasActiveSessions(Room $room): bool {
		$query = $this->connection->getQueryBuilder();
		$query->select('a.room_id')
			->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq(
				'a.id', 's.attendee_id'
			))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('s.id'))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (bool) $row;
	}

	/**
	 * @param Room $room
	 * @return bool
	 */
	public function hasActiveSessionsInCall(Room $room): bool {
		$query = $this->connection->getQueryBuilder();
		$query->select('a.room_id')
			->from('talk_attendees', 'a')
			->leftJoin('a', 'talk_sessions', 's', $query->expr()->eq(
				'a.id', 's.attendee_id'
			))
			->where($query->expr()->eq('a.room_id', $query->createNamedParameter($room->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->isNotNull('s.in_call'))
			->andWhere($query->expr()->neq('s.in_call', $query->createNamedParameter(Participant::FLAG_DISCONNECTED)))
			->setMaxResults(1);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (bool) $row;
	}

	protected function generatePin(int $entropy = 7): string {
		$pin = '';
		// Do not allow to start with a '0' as that is a special mode on the phone server
		// Also there are issues with some providers when you enter the same number twice
		// consecutive too fast, so we avoid this as well.
		$lastDigit = '0';
		for ($i = 0; $i < $entropy; $i++) {
			$lastDigit = $this->secureRandom->generate(1,
				str_replace($lastDigit, '', ISecureRandom::CHAR_DIGITS)
			);
			$pin .= $lastDigit;
		}

		return $pin;
	}
}
