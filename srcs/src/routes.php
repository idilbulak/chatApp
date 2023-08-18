<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

require_once 'controller.php';

function setupRoutes($app) {
    $app->group('/groups', function (RouteCollectorProxy $groups) {
        // List all groups
        $groups->get('', 'GroupController:getAll');

        // Create a new group
        $groups->post('', 'GroupController:create');

        // Routes related to specific group by its ID
        $groups->group('/{group_id}', function (RouteCollectorProxy $group) {
            
            // Join to group:{group_id}
            $group->post('/join', 'GroupController:join');

            // Leave group:{group_id}
            $group->post('/leave', 'GroupController:leave');

            // Delete group:{group_id}
            $group->delete('', 'GroupController:delete');

            // Send a message to group:{group_id}
            $group->post('/messages', 'GroupController:sendMessage')->add('validateContent');

            // Get messages from group:{group_id}
            $group->get('/messages', 'GroupController:getMessages');
        });
    });

    // block any other route
    $app->any('{route:.*}', function (Request $request, Response $response) {
        $response->getBody()->write("Not Found");
        return $response->withStatus(404);
    });
}
