<?php

declare(strict_types=1);

namespace Brave\Slack;

use PDO;
use function json_decode;

class Listener
{
    private string $channel;

    private ?PDO $pdo = null;

    private int $slackRequestCount = 0;

    public function __construct()
    {
        $this->channel = (string) getenv('SLACK_LISTENER_CHANNEL');
    }

    public function run(): void
    {
        $this->out('Starting.');

        $hasMore = true;
        $nextOldest = $this->fetchOldest() + 1;
        while ($hasMore) {
            $result = $this->fetchMessage($nextOldest);
            $data = json_decode((string)$result);
            if ($data && isset($data->messages[0])) {
                $this->out('Found new message: ' . ($data->messages[0]->client_msg_id ?? '(no client_msg_id)'));
                $timestamp = (float) $data->messages[0]->ts;
                $this->storeMessages($timestamp, json_encode($data->messages[0]));
                $hasMore = ((int) $data->has_more) === 1;
                $nextOldest = $timestamp + 1;
            } else {
                $hasMore = false;
            }
        }
        $this->out('Finished.');
    }

    private function fetchMessage(float $oldest): ?string
    {
        $rateLimit = 50;
        if ($this->slackRequestCount > 0) {
            $sleepInSeconds = ceil(60/$rateLimit*10)/10;
            usleep((int) ($sleepInSeconds * 1000 * 1000));
        }
        $this->slackRequestCount ++;

        $token = getenv('SLACK_LISTENER_TOKEN');
        $opts = [
            "http" => [
                "method" => 'GET',
                "header" => "Authorization: Bearer $token",
            ]
        ];
        $context = stream_context_create($opts);

        // https://api.slack.com/methods/conversations.history
        $path = "/conversations.history?channel=$this->channel&limit=1&oldest=$oldest";
        $data = file_get_contents("https://slack.com/api$path", false, $context);

        return $data !== false ? $data : null;
    }

    private function fetchOldest(): float
    {
        $stmt = $this->getPDO()->prepare('SELECT message_ts FROM messages ORDER BY message_ts DESC LIMIT 1');
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return (float)  (isset($rows[0]) ? $rows[0]['message_ts'] : 1630447200); // fallback to Sept. 1 2021
    }

    private function storeMessages(float $ts, string $message): void
    {
        $insert = $this->getPDO()->prepare(
            'INSERT INTO messages (channel, message_ts, message) 
            VALUES (:channel, :message_ts, :message)'
        );
        $insert->execute([':channel' => $this->channel, ':message_ts' => $ts, ':message' => $message]);
    }

    private function getPDO(): PDO
    {
        if ($this->pdo === null) {
            $dns = getenv('SLACK_LISTENER_DSN');
            $this->pdo = new PDO($dns, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        return $this->pdo;
    }

    private function out(string $message): void
    {
        echo gmdate('Y-m-d H:i:s ') . $message . PHP_EOL;
    }
}
