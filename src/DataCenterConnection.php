<?php

declare(strict_types=1);

/**
 * Connection module handling all connections to a datacenter.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\LocalMutex;
use danog\MadelineProto\Loop\Generic\PeriodicLoopInternal;
use danog\MadelineProto\MTProto\ConnectionState;
use danog\MadelineProto\MTProto\MTProtoOutgoingMessage;
use danog\MadelineProto\MTProto\NewAuthKey;
use danog\MadelineProto\MTProto\PermAuthKey;
use danog\MadelineProto\MTProto\TempAuthKey;
use danog\MadelineProto\MTProtoTools\Crypt;
use danog\MadelineProto\Reactive\Publisher;
use danog\MadelineProto\RPCError\DcIdInvalidError;
use danog\MadelineProto\Settings\Connection as ConnectionSettings;
use danog\MadelineProto\Stream\ContextIterator;
use JsonSerializable;
use Revolt\EventLoop;

use function count;

/**
 * Datacenter connection.
 * @internal
 */
final class DataCenterConnection implements JsonSerializable
{
    public const READ_WEIGHT = 1;
    public const READ_WEIGHT_MEDIA = 5;
    public const WRITE_WEIGHT = 10;
    /**
     * Promise for connection.
     *
     */
    private Future $connectionsPromise;
    /**
     * Deferred for connection.
     *
     */
    private ?DeferredFuture $connectionsDeferred = null;
    public readonly NewAuthKey $auth;
    /**
     * Connections open to a certain DC.
     *
     * @var array<int, Connection>
     */
    private array $connections = [];
    /**
     * Connection weights.
     *
     * @var array<int, int>
     */
    private array $availableConnections = [];
    /**
     * Main API instance.
     *
     */
    private MTProto $API;
    /**
     * Connection contexts.
     */
    private ?ContextIterator $ctx = null;
    /**
     * DC ID.
     */
    private int $datacenter;
    /**
     * Loop to keep weights at sane value.
     */
    private ?PeriodicLoopInternal $robinLoop = null;
    /**
     * Decrement roundrobin weight by this value if busy reading.
     *
     */
    private int $decRead = 1;
    /**
     * Decrement roundrobin weight by this value if busy writing.
     *
     */
    private int $decWrite = 10;
    /**
     * Backed up messages.
     *
     */
    private array $backup = [];
    /**
     * Whether this socket has to be reconnected.
     *
     */
    private bool $needsReconnect = false;

    /** @param Publisher<ConnectionState> */
    private Publisher $connectionState;

    public function __construct()
    {
        $this->connectionState = new Publisher(ConnectionState::UNENCRYPTED);
        $this->auth = new NewAuthKey($this->connectionState);
    }
    public function __wakeup(): void
    {
        $this->connectionState ??= new Publisher(ConnectionState::UNENCRYPTED);
        $this->auth ??= new NewAuthKey($this->connectionState);
    }

    /**
     * Indicate if this socket needs to be reconnected.
     *
     * @param boolean $needsReconnect Whether the socket has to be reconnected
     */
    public function needReconnect(bool $needsReconnect): void
    {
        $this->needsReconnect = $needsReconnect;
    }
    /**
     * Whether this sockets needs to be reconnected.
     */
    public function shouldReconnect(): bool
    {
        return $this->needsReconnect;
    }
    public function getCtxs(): ContextIterator
    {
        \assert($this->ctx !== null);
        return $this->ctx;
    }
    private ?LocalMutex $initingAuth = null;
    /**
     * Init auth keys for single DC.
     *
     * @internal
     */
    public function initAuthorization(): void
    {
        $logger = $this->API->logger;
        $this->initingAuth ??= new LocalMutex;
        $logger->logger("Acquiring lock in order to init auth for DC {$this->datacenter}", Logger::NOTICE);
        $lock = $this->initingAuth->acquire();
        try {
            $logger->logger("Initing auth for DC {$this->datacenter}", Logger::NOTICE);
            $this->waitGetConnection();
            $connection = $this->getAuthConnection();
            $this->createSession();
            $cdn = $connection->isCDN();
            $media = $connection->isMedia();
            if (!$this->auth->getTempAuthKey() 
                || !$this->auth->getAuthKey() 
                || !$this->auth->isBound()
            ) {
                if (!$this->auth->getAuthKey() && !$cdn && !$media) {
                    $logger->logger(sprintf('Generating permanent authorization key for DC %s...', $this->datacenter), Logger::NOTICE);
                    $this->auth->setAuthKey($connection->createAuthKey(false, $this->connectionState));
                }
                if ($media) {
                    $connection = $this->API->datacenter->getDataCenterConnection(-$this->datacenter);
                    $connection->initAuthorization();
                    $this->auth->link($connection->auth);
                    if ($this->auth->hasTempAuthKey() && $this->isBound()) {
                        $this->syncAuthorization();
                        return;
                    }
                }
                if (!$cdn) {
                    $logger->logger(sprintf('Generating temporary authorization key for DC %s...', $this->datacenter), Logger::NOTICE);
                    $this->auth->setTempAuthKey(null);
                    $this->auth->setTempAuthKey($connection->createAuthKey(true, $this->connectionState));
                    $this->initConnection();
                    $this->bindTempAuthKey();
                    $this->syncAuthorization();
                } elseif (!$this->hasTempAuthKey()) {
                    $logger->logger(sprintf('Generating temporary authorization key for CDN DC %s...', $this->datacenter), Logger::NOTICE);
                    $this->auth->setTempAuthKey($connection->createAuthKey(true, $this->connectionState));
                    $this->initConnection();
                }
                foreach ($this->connections as $socket) {
                    $socket->flush();
                }
            } elseif (!$cdn) {
                $this->syncAuthorization();
            }
        } finally {
            $logger->logger("Done initing auth for DC {$this->datacenter}", Logger::NOTICE);
            EventLoop::queue($lock->release(...));
        }
        if ($this->hasTempAuthKey()) {
            $connection->pinger?->resume();
        }
    }

    private function initConnection(): void
    {
        $connection = $this->getAuthConnection();
        $this->API->logger('Writing client info (also executing help.getConfig)...', Logger::NOTICE);
        $connection->methodCallAsyncRead('invokeWithLayer', [
            'layer' => $this->API->settings->getSchema()->getLayer(),
            'query' => $this->API->getTL()->serializeMethod(
                'initConnection',
                [
                    'api_id' => $this->API->settings->getAppInfo()->getApiId(),
                    'api_hash' => $this->API->settings->getAppInfo()->getApiHash(),
                    'device_model' => !$connection->isCDN() ? $this->API->settings->getAppInfo()->getDeviceModel() : 'n/a',
                    'system_version' => !$connection->isCDN() ? $this->API->settings->getAppInfo()->getSystemVersion() : 'n/a',
                    'app_version' => $this->API->settings->getAppInfo()->getAppVersion(),
                    'system_lang_code' => $this->API->settings->getAppInfo()->getSystemLangCode(),
                    'lang_code' => $this->API->settings->getAppInfo()->getLangCode(),
                    'lang_pack' => $this->API->settings->getAppInfo()->getLangPack(),
                    'proxy' => $connection->getInputClientProxy(),
                    'query' => $this->API->getTL()->serializeMethod(
                        $connection->isCDN() ? 'ping' : 'help.getConfig',
                        []
                    ),
                ]
            ),
            'authMethod' => true,
        ]);
    }

    /**
     * Bind temporary and permanent auth keys.
     *
     * @internal
     */
    public function bindTempAuthKey(): bool
    {
        $connection = $this->getAuthConnection();
        $logger = $this->API->logger;
        $expires_in = MTProto::PFS_DURATION;
        for ($retry_id_total = 1; $retry_id_total <= $this->API->settings->getAuth()->getMaxAuthTries(); $retry_id_total++) {
            try {
                $logger->logger('Binding authorization keys...', Logger::VERBOSE);
                $nonce = Tools::random(8);
                $expires_at = time() + $expires_in;
                $temp_auth_key_id = $this->getTempAuthKey()->getID();
                $perm_auth_key_id = $this->getPermAuthKey()->getID();
                $temp_session_id = $connection->session_id;
                $message_data = ($this->API->getTL()->serializeObject(['type' => ''], ['_' => 'bind_auth_key_inner', 'nonce' => $nonce, 'temp_auth_key_id' => $temp_auth_key_id, 'perm_auth_key_id' => $perm_auth_key_id, 'temp_session_id' => $temp_session_id, 'expires_at' => $expires_at], 'bindTempAuthKey_inner'));
                $message_id = $connection->msgIdHandler->generateMessageId();
                $seq_no = 0;
                $encrypted_data = Tools::random(16).Tools::packSignedLong($message_id).pack('VV', $seq_no, \strlen($message_data)).$message_data;
                $message_key = substr(sha1($encrypted_data, true), -16);
                $padding = Tools::random(Tools::posmod(-\strlen($encrypted_data), 16));
                [$aes_key, $aes_iv] = Crypt::oldKdf($message_key, $this->getPermAuthKey()->getAuthKey());
                $encrypted_message = $this->getPermAuthKey()->getID().$message_key.Crypt::igeEncrypt($encrypted_data.$padding, $aes_key, $aes_iv);
                $res = $connection->methodCallAsyncRead('auth.bindTempAuthKey', ['perm_auth_key_id' => $perm_auth_key_id, 'nonce' => $nonce, 'expires_at' => $expires_at, 'encrypted_message' => $encrypted_message, 'madelineMsgId' => $message_id, 'authMethod' => true]);
                if ($res === true) {
                    $logger->logger("Bound temporary and permanent authorization keys, DC {$this->datacenter}", Logger::NOTICE);
                    $this->bind();
                    return true;
                }
            } catch (SecurityException $e) {
                $logger->logger('An exception occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...', Logger::WARNING);
            } catch (Exception $e) {
                $logger->logger('An exception occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...', Logger::WARNING);
            } catch (RPCErrorException $e) {
                $logger->logger('An RPCErrorException occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...', Logger::WARNING);
            }
        }
        throw new SecurityException('An error occurred while binding temporary and permanent authorization keys.');
    }
    /**
     * Sync authorization data between DCs.
     */
    private function syncAuthorization(): void
    {
        $socket = $this->getAuthConnection();
        $logger = $this->API->logger;
        if ($this->API->authorized === \danog\MadelineProto\API::LOGGED_IN && !$this->isAuthorized()) {
            foreach ($this->API->datacenter->getDataCenterConnections() as $authorized_dc_id => $authorized_socket) {
                if ($this->API->authorized_dc !== null && $authorized_dc_id !== $this->API->authorized_dc) {
                    continue;
                }
                if ($authorized_socket->hasTempAuthKey()
                    && $authorized_socket->hasPermAuthKey()
                    && $authorized_socket->isAuthorized()
                    && $this->API->authorized === \danog\MadelineProto\API::LOGGED_IN
                    && !$this->isAuthorized()
                    && !$this->API->isCDN($authorized_dc_id)
                    && $authorized_dc_id !== $this->datacenter
                ) {
                    try {
                        $logger->logger('Trying to copy authorization from DC '.$authorized_dc_id.' to DC '.$this->datacenter);
                        $exported_authorization = $this->API->methodCallAsyncRead('auth.exportAuthorization', ['dc_id' => $this->datacenter % 10_000, 'userRelated' => true], $authorized_dc_id);
                        $exported_authorization['authMethod'] = true;
                        $socket->methodCallAsyncRead('auth.importAuthorization', $exported_authorization);
                        $this->authorized(true);
                        break;
                    } catch (DcIdInvalidError $e) {
                        $logger->logger('Failure while syncing authorization from DC '.$authorized_dc_id.' to DC '.$this->datacenter.': '.$e->getMessage(), Logger::ERROR);
                        break;
                    } catch (RPCErrorException|Exception $e) {
                        $logger->logger('Failure while syncing authorization from DC '.$authorized_dc_id.' to DC '.$this->datacenter.': '.$e->getMessage(), Logger::ERROR);
                    }
                    // Turns out this DC isn't authorized after all
                }
            }
        }
    }
    /**
     * Reset MTProto sessions.
     */
    public function resetSession(string $why): void
    {
        foreach ($this->connections as $socket) {
            $socket->resetSession($why);
        }
    }
    /**
     * Create MTProto sessions if needed.
     */
    public function createSession(): void
    {
        foreach ($this->connections as $socket) {
            $socket->createSession();
        }
    }
    /**
     * Has connection context?
     */
    public function hasCtx(): bool
    {
        return isset($this->ctx);
    }
    /**
     * Connect function.
     *
     * @param int $id Optional connection ID to reconnect
     */
    public function connect(int $id = -1): void
    {
        $media = DataCenter::isMedia($this->datacenter) || $this->API->isCDN($this->datacenter);
        if ($media) {
            if (!$this->robinLoop) {
                $this->robinLoop = new PeriodicLoopInternal(
                    $this->API,
                    $this->even(...),
                    "robin loop DC {$this->datacenter}",
                    $this->API->getSettings()->getConnection()->getRobinPeriod()
                );
            }
            $this->robinLoop->start();
        }
        $this->decRead = $media ? self::READ_WEIGHT_MEDIA : self::READ_WEIGHT;
        $this->decWrite = self::WRITE_WEIGHT;
        if ($id === -1 || !isset($this->connections[$id])) {
            if ($this->connections) {
                $this->API->logger('Already connected!', Logger::WARNING);
                return;
            }
            $f = new DeferredFuture;
            $this->connectionsPromise = $f->getFuture();
            $this->connectMore(1);
            $f->complete();
            if (isset($this->connectionsDeferred)) {
                $connectionsDeferred = $this->connectionsDeferred;
                $this->connectionsDeferred = null;
                $connectionsDeferred->complete();
            }
            $this->restoreBackup();
        } else {
            $this->availableConnections[$id] = 0;
            $this->connections[$id]->setExtra($this, $this->connectionState, $this->datacenter, $id);
        }
    }
    /**
     * Connect to the DC using count more sockets.
     *
     * @param integer $count Number of sockets to open
     */
    private function connectMore(int $count): void
    {
        $count += $previousCount = \count($this->connections);
        for ($x = $previousCount; $x < $count; $x++) {
            $connection = new Connection();
            $connection->setExtra($this, $this->connectionState, $this->datacenter, $x);
            $this->connections[$x] = $connection;
            $this->availableConnections[$x] = 0;
        }
    }
    /**
     * Signal that a connection ID disconnected.
     *
     * @param integer $id Connection ID
     */
    public function signalDisconnect(int $id): void
    {
        $backup = $this->connections[$id]->backupSession();
        $list = '';
        foreach ($backup as $k => $message) {
            if ($message->constructor === 'msgs_state_req'
                || $message->constructor === 'ping_delay_disconnect') {
                unset($backup[$k]);
                continue;
            }
            $list .= $message->constructor;
            $list .= ', ';
        }
        $this->API->logger("Backed up {$list} from DC {$this->datacenter}.{$id}");
        $this->backup = array_merge($this->backup, $backup);
        unset($this->connections[$id], $this->availableConnections[$id]);
    }
    /**
     * Close all connections to DC.
     */
    public function disconnect(): void
    {
        $this->connectionsDeferred = new DeferredFuture();
        $this->connectionsPromise = $this->connectionsDeferred->getFuture();
        if (!isset($this->ctx)) {
            return;
        }
        $this->API->logger("Disconnecting from shared DC {$this->datacenter}");
        if ($this->robinLoop) {
            $this->robinLoop->stop();
            $this->robinLoop = null;
        }
        $before = \count($this->backup);
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        $count = \count($this->backup) - $before;
        $this->API->logger("Backed up {$count}, added to {$before} existing messages) from DC {$this->datacenter}");
        $this->connections = [];
        $this->availableConnections = [];
    }
    /**
     * Reconnect to DC.
     */
    public function reconnect(): void
    {
        $this->API->logger("Reconnecting shared DC {$this->datacenter}");
        $this->disconnect();
        $this->connect();
    }
    /**
     * Restore backed up messages.
     */
    private function restoreBackup(): void
    {
        $backup = $this->backup;
        $this->backup = [];
        $count = \count($backup);
        $this->API->logger("Restoring {$count} messages to DC {$this->datacenter}");
        /** @var MTProtoOutgoingMessage */
        foreach ($backup as $message) {
            if ($message->hasSeqno()) {
                $message->setSeqno(null);
            }
            if ($message->hasMsgId()) {
                $message->setMsgId(null);
            }
            if (!($message->getState() & MTProtoOutgoingMessage::STATE_REPLIED)) {
                $this->API->logger("Resending $message to DC {$this->datacenter}");
                EventLoop::queue($this->getConnection()->sendMessage(...), $message);
            } else {
                $this->API->logger("Dropping $message to DC {$this->datacenter}");
            }
        }
    }
    /**
     * Get connection for authorization.
     */
    private function getAuthConnection(): Connection
    {
        return $this->connections[0];
    }
    /**
     * Check if any connection is available.
     *
     * @param integer $id Connection ID
     */
    public function hasConnection(int $id = -1): bool|int
    {
        return $id < 0 ? \count($this->connections) : isset($this->connections[$id]);
    }
    /**
     * Get best socket in round robin, asynchronously.
     */
    public function waitGetConnection(): Connection
    {
        if (empty($this->availableConnections)) {
            $this->connectionsPromise->await();
        }
        return $this->getConnection();
    }
    /**
     * Get best socket in round robin.
     *
     * @param integer $id Connection ID, for manual fetching
     */
    public function getConnection(int $id = -1): Connection
    {
        if ($id >= 0) {
            return $this->connections[$id];
        }
        if (\count($this->availableConnections) <= 1) {
            return $this->connections[0];
        }
        $max = max($this->availableConnections);
        $key = array_search($max, $this->availableConnections, true);
        // Decrease to implement round robin
        $this->availableConnections[$key]--;
        return $this->connections[$key];
    }
    /**
     * Even out round robin values.
     */
    public function even(): void
    {
        if (!$this->availableConnections) {
            return;
        }
        $min = min($this->availableConnections);
        if ($min < 50) {
            foreach ($this->availableConnections as &$count) {
                $count += 50;
            }
        } elseif ($min < 100) {
            $max = DataCenter::isMedia($this->datacenter) || $this->API->isCDN($this->datacenter) ? $this->API->getSettings()->getConnection()->getMaxMediaSocketCount() : 1;
            if (\count($this->availableConnections) < $max) {
                $this->connectMore(2);
            } else {
                foreach ($this->availableConnections as &$value) {
                    $value += 1000;
                }
            }
        }
    }
    /**
     * Indicate that one of the sockets is busy reading.
     *
     * @param boolean $reading Whether we're busy reading
     * @param int     $x       Connection ID
     */
    public function reading(bool $reading, int $x): void
    {
        if (!isset($this->availableConnections[$x])) {
            return;
        }
        $this->availableConnections[$x] += $reading ? -$this->decRead : $this->decRead;
    }
    /**
     * Indicate that one of the sockets is busy writing.
     *
     * @param boolean $writing Whether we're busy writing
     * @param int     $x       Connection ID
     */
    public function writing(bool $writing, int $x): void
    {
        if (!isset($this->availableConnections[$x])) {
            return;
        }
        $this->availableConnections[$x] += $writing ? -$this->decWrite : $this->decWrite;
    }
    /**
     * Set main instance.
     *
     * @param MTProto $API Main instance
     */
    public function setExtra(MTProto $API, int $datacenter, ContextIterator $ctx): void
    {
        $this->datacenter = $datacenter;
        $this->API = $API;
        $this->ctx = $ctx;
    }
    /**
     * Get main instance.
     */
    public function getExtra(): MTProto
    {
        return $this->API;
    }
    /**
     * Get DC-specific settings.
     */
    public function getSettings(): ConnectionSettings
    {
        return $this->API->getSettings()->getConnection();
    }
    /**
     * Get global settings.
     */
    public function getGenericSettings(): Settings
    {
        return $this->API->getSettings();
    }
    /**
     * JSON serialize function.
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['auth' => $this->auth];
    }
    /**
     * Sleep function.
     *
     * @internal
     */
    public function __sleep(): array
    {
        return ['auth'];
    }
}
