<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Bridge\Beanstalkd\Transport;

use Pheanstalk\Contract\PheanstalkInterface;
use Pheanstalk\Exception;
use Pheanstalk\Job as PheanstalkJob;
use Pheanstalk\JobId;
use Pheanstalk\Pheanstalk;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * @author Antonio Pauletich <antonio.pauletich95@gmail.com>
 *
 * @internal
 *
 * @final
 */
class Connection
{
    private const DEFAULT_OPTIONS = [
        'tube_name' => PheanstalkInterface::DEFAULT_TUBE,
        'timeout' => 0,
        'ttr' => 90,
        'bury_on_reject' => false,
    ];

    private string $tube;
    private int $timeout;
    private int $ttr;
    private bool $buryOnReject;

    /**
     * Constructor.
     *
     * Available options:
     *
     * * tube_name: name of the tube
     * * timeout: message reservation timeout (in seconds)
     * * ttr: the message time to run before it is put back in the ready queue (in seconds)
     * * bury_on_reject: bury rejected messages instead of deleting them
     */
    public function __construct(
        private array $configuration,
        private PheanstalkInterface $client,
    ) {
        $this->configuration = array_replace_recursive(self::DEFAULT_OPTIONS, $configuration);
        $this->tube = $this->configuration['tube_name'];
        $this->timeout = $this->configuration['timeout'];
        $this->ttr = $this->configuration['ttr'];
        $this->buryOnReject = $this->configuration['bury_on_reject'];
    }

    public static function fromDsn(#[\SensitiveParameter] string $dsn, array $options = []): self
    {
        if (false === $components = parse_url($dsn)) {
            throw new InvalidArgumentException('The given Beanstalkd DSN is invalid.');
        }

        $connectionCredentials = [
            'host' => $components['host'],
            'port' => $components['port'] ?? PheanstalkInterface::DEFAULT_PORT,
        ];

        $query = [];
        if (isset($components['query'])) {
            parse_str($components['query'], $query);
        }

        $configuration = [];
        foreach (self::DEFAULT_OPTIONS as $k => $v) {
            $value = $options[$k] ?? $query[$k] ?? $v;

            $configuration[$k] = match (\gettype($v)) {
                'integer' => filter_var($value, \FILTER_VALIDATE_INT),
                'boolean' => filter_var($value, \FILTER_VALIDATE_BOOL),
                default => $value,
            };
        }

        // check for extra keys in options
        $optionsExtraKeys = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS));
        if (0 < \count($optionsExtraKeys)) {
            throw new InvalidArgumentException(\sprintf('Unknown option found : [%s]. Allowed options are [%s].', implode(', ', $optionsExtraKeys), implode(', ', array_keys(self::DEFAULT_OPTIONS))));
        }

        // check for extra keys in options
        $queryExtraKeys = array_diff(array_keys($query), array_keys(self::DEFAULT_OPTIONS));
        if (0 < \count($queryExtraKeys)) {
            throw new InvalidArgumentException(\sprintf('Unknown option found in DSN: [%s]. Allowed options are [%s].', implode(', ', $queryExtraKeys), implode(', ', array_keys(self::DEFAULT_OPTIONS))));
        }

        return new self(
            $configuration,
            Pheanstalk::create($connectionCredentials['host'], $connectionCredentials['port'])
        );
    }

    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getTube(): string
    {
        return $this->tube;
    }

    /**
     * @param int  $delay    The delay in milliseconds
     * @param ?int $priority The priority at which the message will be reserved
     *
     * @return string The inserted id
     */
    public function send(string $body, array $headers, int $delay = 0, ?int $priority = null): string
    {
        $message = json_encode([
            'body' => $body,
            'headers' => $headers,
        ]);

        if (false === $message) {
            throw new TransportException(json_last_error_msg());
        }

        try {
            $job = $this->client->useTube($this->tube)->put(
                $message,
                $priority ?? PheanstalkInterface::DEFAULT_PRIORITY,
                (int) ($delay / 1000),
                $this->ttr
            );
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return (string) $job->getId();
    }

    public function get(): ?array
    {
        $job = $this->getFromTube();

        if (null === $job) {
            return null;
        }

        $data = $job->getData();

        $beanstalkdEnvelope = json_decode($data, true);

        return [
            'id' => (string) $job->getId(),
            'body' => $beanstalkdEnvelope['body'],
            'headers' => $beanstalkdEnvelope['headers'],
        ];
    }

    private function getFromTube(): ?PheanstalkJob
    {
        try {
            return $this->client->watchOnly($this->tube)->reserveWithTimeout($this->timeout);
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function ack(string $id): void
    {
        try {
            $this->client->useTube($this->tube)->delete(new JobId((int) $id));
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function reject(string $id, ?int $priority = null, bool $forceDelete = false): void
    {
        try {
            if (!$forceDelete && $this->buryOnReject) {
                $this->client->useTube($this->tube)->bury(new JobId((int) $id), $priority ?? PheanstalkInterface::DEFAULT_PRIORITY);
            } else {
                $this->client->useTube($this->tube)->delete(new JobId((int) $id));
            }
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function keepalive(string $id): void
    {
        try {
            $this->client->useTube($this->tube)->touch(new JobId((int) $id));
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    public function getMessageCount(): int
    {
        try {
            $this->client->useTube($this->tube);
            $tubeStats = $this->client->statsTube($this->tube);
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return (int) $tubeStats['current-jobs-ready'];
    }

    public function getMessagePriority(string $id): int
    {
        try {
            $jobStats = $this->client->statsJob(new JobId((int) $id));
        } catch (Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return (int) $jobStats['pri'];
    }
}
