<?php

declare(strict_types=1);

namespace DockerCli\Panel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class HttpResponse
{
    public static function forRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' || $request->getUri()->getPath() !== '/') {
            return new Response(
                404,
                ['Content-Type' => 'text/html; charset=UTF-8'],
                '<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found</title><body><h1>404</h1></body></html>'
            );
        }

        $body = <<<'HTML'
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>docker-cli panel</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#101827;color:#f8fafc;font:16px system-ui,sans-serif}main{text-align:center;padding:3rem;border:1px solid #334155;border-radius:1.5rem;background:#172033;box-shadow:0 24px 70px #0008}h1{margin:0;font-size:clamp(3rem,10vw,7rem);background:linear-gradient(120deg,#60a5fa,#34d399);color:transparent;background-clip:text;-webkit-background-clip:text}</style>
<main><h1>Hello World</h1><p>docker-cli administrative panel</p></main>
</html>
HTML;

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
