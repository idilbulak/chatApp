<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../config/config.php';

class GroupController {
    
    public function getAll(Request $request, Response $response) {

        $db = getDatabaseConnection();

        $stmt = $db->query('SELECT * FROM groups');
        $groups = $stmt->fetchAll(PDO::FETCH_OBJ);
    
        $response->getBody()->write(json_encode($groups));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response) {
        $db = getDatabaseConnection();

        // JSON'dan veriyi çek
        $data = $request->getParsedBody();
        $groupName = $data['group_name'] ?? null;
        $groupAdminId = $data['user_id'] ?? null;

        // group_admin kontrolü
        if (!$groupAdminId) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Group admin ID is required'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // group_name kontrolü
        if (!$groupName) {
            $randomNumber = rand(1000, 9999);
            $timestamp = time();
            $groupName = "group" . $randomNumber . "_" . $timestamp;
        } else if (strlen($groupName) > 100) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Group name is too long'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Veritabanına grup ekle
        $stmt = $db->prepare('INSERT INTO groups (group_name, group_admin) VALUES (:group_name, :group_admin)');
        $stmt->bindParam(':group_name', $groupName);
        $stmt->bindParam(':group_admin', $groupAdminId, PDO::PARAM_INT);
        $stmt->execute();

        $newGroupId = $db->lastInsertId();

        // group_members tablosuna grup adminini ekleyin
        $stmtMember = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
        $stmtMember->bindParam(':group_id', $newGroupId, PDO::PARAM_INT);
        $stmtMember->bindParam(':user_id', $groupAdminId, PDO::PARAM_INT);
        $stmtMember->execute();

        // JSON olarak yanıt dön
        $responseData = ['id' => $newGroupId, 'group_name' => $groupName, 'group_admin' => $groupAdminId];
        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function join(Request $request, Response $response, $args) {
        $db = getDatabaseConnection();

        $groupId = $args['group_id'];

        // Veriyi JSON'dan çek
        $data = $request->getParsedBody();
        $userId = $data['user_id'] ?? null;

        if (!$userId || !$groupId) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'User ID and Group ID are required'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // TODO: group_id ve user_id'nin geçerli olup olmadığını kontrol edin. Eğer değilse, hata dön.
        $stmtGroup = $db->prepare('SELECT COUNT(*) as count FROM groups WHERE group_id = :group_id');
        $stmtGroup->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmtGroup->execute();
        $groupCount = $stmtGroup->fetch(PDO::FETCH_OBJ)->count;

        if ($groupCount == 0) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Invalid group ID'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Veritabanına yeni üyeyi ekle
        $stmt = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        // JSON olarak yanıt dön
        $responseData = ['group_id' => $groupId, 'user_id' => $userId, 'status' => 'joined'];
        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');

    }

    public function leave(Request $request, Response $response, $args) {
        $db = getDatabaseConnection();

        $groupId = $args['group_id'];
        $userId = $request->getParsedBody()['user_id'] ?? null;

        // Admin kontrolü
        $stmt = $db->prepare('SELECT group_admin FROM groups WHERE group_id = :group_id');
        $stmt->bindParam(':group_id', $groupId);
        $stmt->execute();
        $adminId = $stmt->fetchColumn();

        if ($userId == $adminId) {
            // Eğer grupta başka üyeler varsa, rastgele birini admin yap
            $stmtOtherMembers = $db->prepare('SELECT user_id FROM group_members WHERE group_id = :group_id AND user_id != :user_id LIMIT 1');
            $stmtOtherMembers->bindParam(':group_id', $groupId);
            $stmtOtherMembers->bindParam(':user_id', userId);
            $stmtOtherMembers->execute();
            $newAdmin = $stmtOtherMembers->fetchColumn();

            if ($newAdmin) {
                $stmtNewAdmin = $db->prepare('UPDATE groups SET group_admin = :new_admin WHERE group_id = :group_id');
                $stmtNewAdmin->bindParam(':new_admin', $newAdmin);
                $stmtNewAdmin->bindParam(':group_id', $groupId);
                $stmtNewAdmin->execute();
            } else {
                // Eğer grupta başka üye yoksa, grubu sil
                $stmtDelete = $db->prepare('DELETE FROM groups WHERE group_id = :group_id');
                $stmtDelete->bindParam(':group_id', $groupId);
                $stmtDelete->execute();
            }
        }

        // Kullanıcıyı gruptan çıkar
        $stmtLeave = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
        $stmtLeave->bindParam(':group_id', $groupId);
        $stmtLeave->bindParam(':user_id', $userId);
        $stmtLeave->execute();

        $response->getBody()->write(json_encode(['status' => 'left']));
        return $response->withHeader('Content-Type', 'application/json');

    }

    public function delete(Request $request, Response $response, $args) {
        $db = getDatabaseConnection();

        $groupId = $args['group_id'];
        $userId = $request->getParsedBody()['user_id'] ?? null; // kullanıcının ID'sini request'ten al

        // Admin kontrolü
        $stmt = $db->prepare('SELECT group_admin FROM groups WHERE group_id = :group_id');
        $stmt->bindParam(':group_id', $groupId);
        $stmt->execute();
        $adminId = $stmt->fetchColumn();

        if ($userId != $adminId) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Only the group admin can delete the group'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403); // Forbidden
        }

        // Grubu sil
        $stmtDelete = $db->prepare('DELETE FROM groups WHERE group_id = :group_id');
        $stmtDelete->bindParam(':group_id', $groupId);
        $stmtDelete->execute();

        // Gruba ait tüm üyelikleri sil
        $stmtDeleteMembers = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id');
        $stmtDeleteMembers->bindParam(':group_id', $groupId);
        $stmtDeleteMembers->execute();

        $response->getBody()->write(json_encode(['status' => 'success']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function sendMessage(Request $request, Response $response, $args) {
        // connect to database
        $db = getDatabaseConnection();

        // get the group_id from uri and check if it is valid, if not return 400:bad request
        $groupId = $args['group_id'];
        if (!$this->checkGroupExists($groupId)) {
            return $this->resError($response, 'Invalid group ID', 400);
        }

        // get the user_id and content from body and check if it valid, if not return 400:bad request
        $data = $request->getParsedBody();
        $userId = $data['user_id'] ?? null;
        $content = $data['content'] ?? null;
        if (!$userId || !$content) {
            return $this->resError($response, 'User ID and content are required', 400);
        }

        // check if the user is a member of the group, if not return 403:forbidden
        if (!$this->checkGroupMembership($groupId, $userId)) {
            return $this->resError($response, 'You are not a member of this group', 403);
        }

        // bunu middleware a al validation....
        $maxContentLength = 1000; // max character length for content
        if (strlen($content) > $maxContentLength) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => "Content exceeds the maximum allowed length of {$maxContentLength} characters."
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // insert message to database
        $stmt = $db->prepare('INSERT INTO messages (group_id, user_id, content) VALUES (:group_id, :user_id, :content)');
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':content', $content);
        $stmt->execute();

        $newMessageId = $db->lastInsertId();

        $responseData = ['message_id' => $newMessageId, 'status' => 'sent'];
        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');

    }

    public function getMessages(Request $request, Response $response, $args) {
        // connect to database
        $db = getDatabaseConnection();

        // get the group_id from uri and check if it is valid, if not return 400:bad request
        $groupId = $args['group_id'];
        if (!$this->checkGroupExists($groupId)) {
            return $this->resError($response, 'Invalid group ID', 400);
        }

        // get the user_id from body and check if it is valid, if not return 400:bad request
        $data = $request->getParsedBody();
        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            return $this->resError($response, 'User ID is required', 400);
        }

        // check if the user is a member of the group, if not return 403:forbidden
        if (!$this->checkGroupMembership($groupId, $userId)) {
            return $this->resError($response, 'You are not a member of this group', 403);
        }

        // get all the messages
        $stmt = $db->prepare('SELECT * FROM messages WHERE group_id = :group_id ORDER BY timestamp ASC');
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_OBJ);

        // return json response
        $response->getBody()->write(json_encode($messages));
        return $response->withHeader('Content-Type', 'application/json');
    }

    //  returns true, if the user by {user_id} is a member of group by {group_id}
    public function checkGroupMembership($groupId, $userId) {
        $db = getDatabaseConnection();

        $stmt = $db->prepare('SELECT COUNT(*) as count FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $membershipCount = $stmt->fetch(PDO::FETCH_OBJ)->count;

        return $membershipCount > 0;
    }

    //  returns true, if the group by {group_id} exists
    public function checkGroupExists($groupId) {
        $db = getDatabaseConnection();

        $stmt = $db->prepare('SELECT COUNT(*) as count FROM groups WHERE group_id = :group_id');
        $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();
        $groupCount = $stmt->fetch(PDO::FETCH_OBJ)->count;

        return $groupCount > 0;
    }

    // error response
    public function resError($response, $message, $statusCode) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => $message
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }

}