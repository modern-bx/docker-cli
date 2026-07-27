<?php

declare(strict_types=1);

namespace DockerCli\Panel\WebSocket;

use DockerCli\Panel\Dto\ErrorResponseDto;
use DockerCli\Panel\Dto\Request\EmptyRequestDto;
use DockerCli\Panel\Http\ResponseEmitter;
use DockerCli\Panel\JwtTokenService;
use DockerCli\Panel\StateController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;

final readonly class PanelStateChannel
{
    public const PATH = '/ws';
    public const NAME = 'panel:system';

    public function __construct(
        private StateController $state,
        private JwtTokenService $tokens,
        private ResponseEmitter $responses,
    ) {
    }

    public function handles(ServerRequestInterface $request): bool
    {
        return $request->getUri()->getPath() === self::PATH;
    }

    public function upgrade(ServerRequestInterface $request): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $token = is_string($query['token'] ?? null) ? $query['token'] : '';
        if (($query['channel'] ?? null) !== self::NAME || $this->tokens->login($token) === null) {
            return $this->responses->json(401, new ErrorResponseDto('Сессия истекла.'));
        }

        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        if (strtolower($request->getHeaderLine('Upgrade')) !== 'websocket'
            || $request->getHeaderLine('Sec-WebSocket-Version') !== '13'
            || strlen((string) base64_decode($key, true)) !== 16) {
            return $this->responses->json(400, new ErrorResponseDto('Некорректный WebSocket-запрос.'));
        }

        $outgoing = new ThroughStream();
        $incoming = new ThroughStream();
        $stream = new CompositeStream($outgoing, $incoming);
        $timer = Loop::addPeriodicTimer(1.0, fn () => $this->sendState($outgoing));
        Loop::futureTick(fn () => $this->sendState($outgoing));

        $buffer = '';
        $incoming->on('data', function (string $data) use ($stream, $outgoing, &$buffer): void {
            $buffer .= $data;
            while (strlen($buffer) >= 2) {
                $first = ord($buffer[0]);
                $second = ord($buffer[1]);
                $length = $second & 0x7f;
                $offset = 2;
                if ($length === 126) {
                    if (strlen($buffer) < 4) return;
                    $length = unpack('n', substr($buffer, 2, 2))[1];
                    $offset = 4;
                } elseif ($length === 127) {
                    $outgoing->write($this->frame('', 0x8));
                    $stream->close();
                    return;
                }
                if (($second & 0x80) === 0 || strlen($buffer) < $offset + 4 + $length) return;
                $mask = substr($buffer, $offset, 4);
                $payload = substr($buffer, $offset + 4, $length);
                $buffer = substr($buffer, $offset + 4 + $length);
                $payload = implode('', array_map(
                    static fn (string $byte, int $index): string => $byte ^ $mask[$index % 4],
                    str_split($payload),
                    array_keys(str_split($payload)),
                ));
                $opcode = $first & 0x0f;
                if ($opcode === 0x8) {
                    $outgoing->write($this->frame($payload, 0x8));
                    $stream->close();
                    return;
                }
                if ($opcode === 0x9) $outgoing->write($this->frame($payload, 0xA));
            }
        });
        $stream->on('close', static fn () => Loop::cancelTimer($timer));

        return new Response(101, [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Accept' => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ], $stream);
    }

    private function sendState(ThroughStream $stream): void
    {
        if (!$stream->isWritable()) return;
        try {
            $payload = json_encode([
                'channel' => self::NAME,
                'data' => $this->state->state(new EmptyRequestDto()),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stream->write($this->frame($payload));
        } catch (\Throwable) {
            // A transient Docker error must not tear down the channel.
        }
    }

    private function frame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length < 126) return $header . chr($length) . $payload;
        if ($length <= 0xffff) return $header . chr(126) . pack('n', $length) . $payload;

        return $header . chr(127) . pack('NN', 0, $length) . $payload;
    }
}
