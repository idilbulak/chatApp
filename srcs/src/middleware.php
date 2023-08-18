<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

function validateContent(Request $request, RequestHandler $handler): Response {
    $data = $request->getParsedBody();

    // validate content
    if (isset($data['content'])) {
        // clean special characters
        $data['content'] = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        
        // check content length
        if (empty($data['content']) || strlen($data['content']) > 1000) {
            $response = new \Slim\Psr7\Response();
            return jsonResponse($response, ['error' => 'Message content is too long.'], 400);
        }
        $request = $request->withParsedBody($data);
    }

    return $handler->handle($request);
}


function jsonResponse(Response $response, $data, $status = 200) {
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}
