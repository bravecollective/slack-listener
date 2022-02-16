<?php

declare(strict_types=1);

namespace Brave\Slack;

use PDO;
use function json_decode;

class Listener
{
    private string $channel;
    private array $knownUsers = [];

    private ?PDO $pdo = null;

    private int $slackRequestCount = 0;

    public function __construct()
    {
        setlocale(LC_ALL, 'C');
        $this->channel = (string) getenv('SLACK_LISTENER_CHANNEL');
    }

    public function run(): void
    {
        $this->out('Starting.');

        $relayType = (string) getenv('SLACK_LISTENER_RELAY_TYPE');
        $relaySource = (string) getenv('SLACK_LISTENER_RELAY_SOURCE');

        $hasMore = true;
        $nextOldest = $this->fetchOldest() + 1;
        while ($hasMore) {
            $result = $this->fetchMessage($nextOldest);
            $data = json_decode((string)$result, true);
            if ($data && isset($data['messages'][0])) {
                $this->out('Found new message: ' . ($data['messages'][0]['client_msg_id'] ?? '(no client_msg_id)'));
                $timestamp = (float) $data['messages'][0]['ts'];

                $storedID = $this->storeMessages($timestamp, json_encode($data['messages'][0]));

                if ($relaySource === 'Receipt' and !is_null($storedID) and $storedID !== 0) {
                    $this->relayMessage($relayType, $storedID, $timestamp, $data['messages'][0]);
                }

                $hasMore = ((int) $data['has_more']) === 1;
                $nextOldest = $timestamp + 1;
            } else {
                $hasMore = false;
            }
        }

        if ($relaySource === 'Database') {
            $this->relayBacklog($relayType);
        }

        $this->out('Finished.');
    }

    private function fetchMessage(float $oldest): ?string
    {
        $context = $this->getContext();

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

    private function storeMessages(float $ts, string $message): ?int
    {
        $insert = $this->getPDO()->prepare(
            'INSERT INTO messages (channel, message_ts, message, relayed)
            VALUES (:channel, :message_ts, :message, :relayed);'
        );
        $insert->execute([':channel' => $this->channel, ':message_ts' => $ts, ':message' => $message, ':relayed' => 0]);
        $insertedID = $this->getPDO()->lastInsertId();

        return (int) $insertedID;
    }

    private function relayBacklog(string $destinationType): void
    {
        $stmt = $this
            ->getPDO()
            ->prepare('SELECT id, message_ts, message FROM messages WHERE relayed=:relayed ORDER BY message_ts');
        $stmt->execute([':relayed' => 0]);
        $foundMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($foundMessages as $eachMessage) {
            $this->relayMessage(
                $destinationType,
                (int)$eachMessage["id"],
                (float)$eachMessage["message_ts"],
                json_decode($eachMessage["message"], true)
            );
        }
    }

    private function getUser(string $userID): ?string
    {
        $context = $this->getContext();

        // https://api.slack.com/methods/users.info
        $path = "/users.info?user=$userID";
        $data = file_get_contents("https://slack.com/api$path", false, $context);

        return $data !== false ? $data : null;
    }

    private function parseHeaders(array $headerList): array
    {
        $parsedHeaders = ["Status Code" => $headerList[0], "Headers" => []];

        foreach (array_slice($headerList, 1) as $eachHeader) {
            $splitHeader = explode(":", strtolower($eachHeader));
            $headerTitle = $splitHeader[0];
            $headerData = implode(":", array_slice($splitHeader, 1));

            $parsedHeaders["Headers"][$headerTitle] = $headerData;
        }

        return $parsedHeaders;
    }

    private function getSubstrings(string $initialMessage, string $destinationType): array
    {
        //Slack's Message Limit is documented as 40,000 characters, however the actual limit seems to be around only a couple thousand.
        $maxLengths = [
            "Discord" => 2000,
            "Slack" => 2000
        ];

        $messageArray = [""];
        $currentIndex = 0;

        $lineSplit = preg_split("/([\\n])/", $initialMessage, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($lineSplit as $eachLine) {

            if (strlen($messageArray[$currentIndex]) + strlen($eachLine) > $maxLengths[$destinationType]) {

                $sentenceSplit = preg_split("/([\.\?\!])/", $eachLine, -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($sentenceSplit as $eachSentence) {

                    if (strlen($messageArray[$currentIndex]) + strlen($eachSentence) > $maxLengths[$destinationType]) {

                        $currentIndex++;
                        $messageArray[$currentIndex] = $eachSentence;

                    }
                    else {

                        $messageArray[$currentIndex] .= $eachSentence;

                    }

                }

            }
            else {

                $messageArray[$currentIndex] .= $eachLine;

            }

        }

        return $messageArray;

    }

    private function relayMessage(string $destinationType, int $id, float $ts, array $message): void
    {
        if ($destinationType === "None") {
            return;
        }

        $destinationURL = (string) getenv('SLACK_LISTENER_RELAY_WEBHOOK');
        $context = [
            "http" => [
                "ignore_errors" => true,
                "header" => [
                    "Content-Type: application/json"
                ],
                "method" => "POST"
            ]
        ];

        if (!isset($this->knownUsers[$message["user"]])) {
            $userInfo = $this->getUser($message["user"]);
            if (!is_null($userInfo)) {
                $parsedUserInfo = json_decode($userInfo, true);

                if ($parsedUserInfo["ok"]) {

                    $this->knownUsers[$message["user"]] = [
                        "Name" => $parsedUserInfo["user"]["profile"]["real_name"],
                        "Username" => (isset($parsedUserInfo["user"]["profile"]["display_name"]) and $parsedUserInfo["user"]["profile"]["display_name"] !== "") ? $parsedUserInfo["user"]["profile"]["display_name"] : $parsedUserInfo["user"]["profile"]["real_name"],
                        "Image" => $parsedUserInfo["user"]["profile"]["image_original"] ?? null,
                    ];

                }

            }
        }

        if (isset($this->knownUsers[$message["user"]])) {
            $relayedName = $this->knownUsers[$message["user"]]["Name"];
            $relayedUsername = $this->knownUsers[$message["user"]]["Username"];
            $relayedImage = $this->knownUsers[$message["user"]]["Image"];
        } else {
            $relayedName = "Unknown User " . $message["user"];
            $relayedUsername = $message["user"];
            $relayedImage = null;
        }

        $relayList = $this->getSubstrings($message["text"], $destinationType);

        $partsCounter = 1;
        $successfullySent = 0;

        foreach ($relayList as $eachRelay) {

            if ($destinationType === "Discord") {
                $context["http"]["content"] = [
                    "username" => $relayedName,
                    "avatar_url" => $relayedImage,
                    "content" => str_replace(
                        ["<!everyone>", "<!channel>", "<!here>", "*"],
                        ["@everyone", "@everyone", "@here", "**"] ,
                        htmlspecialchars_decode($eachRelay)
                    )
                ];

                if ($partsCounter === count($relayList)) {

                    $context["http"]["content"]["embeds"] = [
                        [
                            "color" => 15844367,
                            "footer" => [
                                "text" => "Original message sent by " . $relayedUsername . " on " .
                                    date('F jS, Y \a\t G:i:s \U\T\C', (int)$ts) . "."
                            ]
                        ]
                    ];

                }

            } elseif ($destinationType === "Slack") {
                $context["http"]["content"] = [
                    "text" => htmlspecialchars_decode($eachRelay),
                    "blocks" => [
                        [
                            "type" => "section",
                            "text" => [
                                "type" => "mrkdwn",
                                "text" => htmlspecialchars_decode($eachRelay)
                            ]
                        ]
                    ]
                ];

                if ($partsCounter === count($relayList)) {
                    $context["http"]["content"]["blocks"][] = [
                        "type" => "divider"
                    ];
                    $context["http"]["content"]["blocks"][] = [
                        "type" => "context",
                        "elements" => [
                            [
                                "type" => "mrkdwn",
                                "text" => "Original message sent by " . $relayedUsername . " on " .
                                    date('F jS, Y \a\t G:i:s \U\T\C', (int)$ts) . "."
                            ]
                        ]
                    ];
                }
            }

            $context["http"]["content"] = json_encode($context["http"]["content"]);

            for ($remainingRetries = 5; $remainingRetries >= 0; $remainingRetries--) {
                $finalizedContext = stream_context_create($context);
                $response = file_get_contents($destinationURL, false, $finalizedContext);
                $responseHeaders = $this->parseHeaders($http_response_header);

                if (
                    strpos($responseHeaders["Status Code"], "204") !== false ||
                    strpos($responseHeaders["Status Code"], "200") !== false
                ) {

                    $successfullySent++;
                    $this->out("Relayed " . ($message["client_msg_id"] ?? "(no client_msg_id)") . " part " . $partsCounter . " / " . count($relayList));
                    sleep(1);
                    break;
                } elseif (isset($responseHeaders["Headers"]["retry-after"])) {
                    $waitTime = ($responseHeaders["Headers"]["retry-after"] <= 120 ?
                        $responseHeaders["Headers"]["retry-after"] :
                        ceil($responseHeaders["Headers"]["retry-after"] / 1000));
                    $this->out(
                        "Encountered a Rate-Limit relaying " . ($message["client_msg_id"] ?? "(no client_msg_id)") . " part " . $partsCounter . " / " . count($relayList) . ". Waiting " . $waitTime . " seconds with " . $remainingRetries . " attempts remaining..."
                    );
                    sleep((int)$waitTime);
                } else {
                    $this->out(
                        "Encountered an error relaying " . ($message["client_msg_id"] ?? "(no client_msg_id)") . " part " . $partsCounter . " / " . count($relayList) . ". " . $remainingRetries . " attempts remaining..."
                    );
                    $this->out((string)$response);
                    sleep(1);
                }
            }

            $partsCounter++;

        }

        if ($successfullySent > 0) {
            $update = $this->getPDO()->prepare('UPDATE messages SET relayed=:relayed WHERE id = :id');
            $update->execute([':relayed' => 1, ':id' => $id]);

            if ($successfullySent !== count($relayList)) {

                $this->out(
                    "Some parts of " . ($message["client_msg_id"] ?? "(no client_msg_id)") . " failed to relay! Because part of the message successfully relayed it will not attempt to be relayed again."
                );

            }
        }
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

    /**
     * @return resource
     */
    private function getContext()
    {
        $rateLimit = 50;
        if ($this->slackRequestCount > 0) {
            $sleepInSeconds = ceil(60 / $rateLimit * 10) / 10;
            usleep((int)($sleepInSeconds * 1000 * 1000));
        }
        $this->slackRequestCount++;

        $token = getenv('SLACK_LISTENER_TOKEN');
        $opts = [
            "http" => [
                "method" => 'GET',
                "header" => "Authorization: Bearer $token",
            ]
        ];

        return stream_context_create($opts);
    }
}
