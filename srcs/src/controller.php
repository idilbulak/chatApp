<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GroupController {
    
    // get all groups with their group_id and group_name
    public function getAll(Request $request, Response $response) {
        // connect to database
        $db = getDatabaseConnection();

        // prepare a SQL query to fetch group_id and group_name from the 'groups' table
        $stmt = $db->query('SELECT group_id, group_name FROM groups');
        // execute the SQL query and fetch all results as objects
        $groups = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        // return json response
        return jsonResponse($response, $groups);
    }

    // create a new group with userID (which will be the admin of the group) also has an option to give a name to group
    public function create(Request $request, Response $response) {
        // connect to database
        $db = getDatabaseConnection();

        // get the user_id and group_name from body
        $data = $request->getParsedBody();
        $groupName = $data['group_name'] ?? null;
        $groupAdminId = $data['user_id'] ?? null;

        // check if there is groupAdmin, if not return 400:bad request
        if (!$groupAdminId) {
            return $this->resError($response, 'Group admin ID is required', 400);
        }

        // if groupName is not provided, give a random & unique name
        if (!$groupName) {
            $randomNumber = rand(1000, 9999);
            $timestamp = time();
            $groupName = "group" . $randomNumber . "_" . $timestamp;
        } else if (strlen($groupName) > 100) {
            return $this->resError($response, 'Group name is too long', 400);
        }

        try {
            // prepare an SQL statement for inserting data into the 'groups' table
            $stmt = $db->prepare('INSERT INTO groups (group_name, group_admin) VALUES (:group_name, :group_admin)');
            // bind the value of the $groupName variable to the ':group_name' parameter
            $stmt->bindParam(':group_name', $groupName);
            // bind the value of the $groupAdminId variable to the ':group_admin' parameter and specify its type as integer
            $stmt->bindParam(':group_admin', $groupAdminId, PDO::PARAM_INT);
            // execute the prepared statement
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        // get the new groupId
        $newGroupId = $db->lastInsertId();

        // add the admin to group_members table
        try {
            // prepare an SQL statement to insert data into the 'group_members' table
            $stmtMember = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
            // bind the value of the $newGroupId variable to the ':group_id' parameter and specify its type as integer
            $stmtMember->bindParam(':group_id', $newGroupId, PDO::PARAM_INT);
            // bind the value of the $groupAdminId variable to the ':user_id' parameter and specify its type as integer
            $stmtMember->bindParam(':user_id', $groupAdminId, PDO::PARAM_INT);
            // execute the prepared statement to insert the data
            $stmtMember->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        // prepare json response
        $responseData = ['id' => $newGroupId, 'group_name' => $groupName, 'group_admin' => $groupAdminId];
        // return json response
        return jsonResponse($response, $responseData);
    }

    public function join(Request $request, Response $response, $args) {
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

        // add the member to group_members in the database
        try {
            // prepare an SQL statement to insert a new member into the 'group_members' table
            $stmt = $db->prepare('INSERT INTO group_members (group_id, user_id) VALUES (:group_id, :user_id)');
            // bind the value of the $groupId variable to the ':group_id' parameter and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // bind the value of the $userId variable to the ':user_id' parameter and specify its type as integer
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            // Execute the prepared statement
            $stmt->execute();
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                // if already a member in the database
                return $this->resError($response, 'User is already a member of this group.', 400);
            } else {
                // 500:internal server error
                return $this->resError($response, 'Database error.', 500);
            }
        }

        // prepare json response
        $responseData = ['group_id' => $groupId, 'user_id' => $userId, 'status' => 'joined'];
        // return json response
        return jsonResponse($response, $responseData);
    }

    public function leave(Request $request, Response $response, $args) {
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

        // check if the user is admin of the group
        if ($this->isAdmin($groupId, $userId)) {
            // if there are other members, transfer the adminship to another member, if not delete the group
            $this->transferAdminOrDeleteGroup($groupId, $userId);
        }

        // Delete the member from the group
        $this->removeMemberFromGroup($groupId, $userId);

        // return json response
        return jsonResponse($response, ['status' => 'left']);
    }

    public function delete(Request $request, Response $response, $args) {
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

        // check if user is admin of the group, if not return 403:forbidden
        if (!$this->isAdmin($groupId, $userId)) {
            return $this->resError($response, 'Only the group admin can delete the group', 403);
        }

        // delete all the members from the group and the group itself
        $this->deleteAllMembersAndGroup($groupId);
        
        // return json response
        return jsonResponse($response, ['status' => 'success']);
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

        // insert message to database
        try {
            // prepare an SQL statement to insert a new message into the 'messages' table
            $stmt = $db->prepare('INSERT INTO messages (group_id, user_id, content) VALUES (:group_id, :user_id, :content)');
            // bind the value of the $groupId variable to the ':group_id' parameter and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // bind the value of the $userId variable to the ':user_id' parameter and specify its type as integer
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            // bind the value of the $content variable to the ':content' parameter
            $stmt->bindParam(':content', $content);
            // Execute the prepared statement
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        }

        // get the id of the message
        $newMessageId = $db->lastInsertId();

        // prepare json response data
        $responseData = ['message_id' => $newMessageId, 'status' => 'sent'];
        // return json response
        return jsonResponse($response, $responseData);
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
        try {
            // prepare an SQL statement to fetch all messages from the 'messages' table 
            // where the group_id matches and order them by timestamp in ascending order
            $stmt = $db->prepare('SELECT * FROM messages WHERE group_id = :group_id ORDER BY timestamp ASC');
            // bind the value of the $groupId variable to the ':group_id' parameter and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // execute the prepared statement
            $stmt->execute();
            // fetch all results as objects and store them in the $messages variable
            $messages = $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        // return json response
        return jsonResponse($response, $messages);
    }

    //  returns true, if the user by {user_id} is a member of group by {group_id}
    public function checkGroupMembership($groupId, $userId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to count the number of rows in the 'group_members' table 
            // where both the group_id and user_id match the given values
            $stmt = $db->prepare('SELECT COUNT(*) as count FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
            // bind the value of the $groupId variable to the ':group_id' parameter and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // bind the value of the $userId variable to the ':user_id' parameter and specify its type as integer
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            // execute the prepared statement
            $stmt->execute();
            // fetch the result (which will contain the count) and store it in the $membershipCount variable
            $membershipCount = $stmt->fetch(PDO::FETCH_OBJ)->count;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        return $membershipCount > 0;
    }

    //  returns true, if the group by {group_id} exists
    public function checkGroupExists($groupId) {
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to count the number of rows in the 'groups' table 
            // where the group_id matches the given value
            $stmt = $db->prepare('SELECT COUNT(*) as count FROM groups WHERE group_id = :group_id');
            // bind the value of the $groupId variable to the ':group_id' parameter and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // execute the prepared statement
            $stmt->execute();
            // fetch the result (which will contain the count) and store it in the $groupCount variable
            $groupCount = $stmt->fetch(PDO::FETCH_OBJ)->count;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        return $groupCount > 0;
    }

    // check if the user is admin
    public function isAdmin($groupId, $userId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to retrieve the 'group_admin' column from the 'groups' table 
            // where the group_id matches the given value
            $stmt = $db->prepare('SELECT group_admin FROM groups WHERE group_id = :group_id');
            // bind the value of the $groupId variable to the ':group_id' parameter
            $stmt->bindParam(':group_id', $groupId);
            // execute the prepared statement
            $stmt->execute();
            // fetch the single column result (which will be the group_admin's ID) and store it in the $adminId variable
            $adminId = $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        return $userId == $adminId;
    }

    // give the adminship to another member, if no other member delete the group
    public function transferAdminOrDeleteGroup($groupId, $userId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to select another member from the 'group_members' table
            // who isn't the current user (i.e., not having user_id equal to :user_id)
            // and belongs to the specific group identified by :group_id
            $stmtOtherMembers = $db->prepare('SELECT user_id FROM group_members WHERE group_id = :group_id AND user_id != :user_id LIMIT 1');
            // bind the value of the $groupId variable to the ':group_id' parameter
            $stmtOtherMembers->bindParam(':group_id', $groupId);
            // bind the value of the $userId variable to the ':user_id' parameter 
            // to exclude this user from the results
            $stmtOtherMembers->bindParam(':user_id', $userId);
            // execute the prepared statement
            $stmtOtherMembers->execute();
            // fetch the single column result (which will be the user_id of another member) and store it in the $newAdmin variable
            $newAdmin = $stmtOtherMembers->fetchColumn();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 

        if ($newAdmin) {
            try {
                // prepare an SQL statement to update the 'group_admin' field in the 'groups' table 
                // for a specific group identified by :group_id
                $stmtNewAdmin = $db->prepare('UPDATE groups SET group_admin = :new_admin WHERE group_id = :group_id');
                // bind the value of the $newAdmin variable (which contains the ID of the new admin) 
                // to the ':new_admin' parameter
                $stmtNewAdmin->bindParam(':new_admin', $newAdmin);
                // bind the value of the $groupId variable to the ':group_id' parameter
                $stmtNewAdmin->bindParam(':group_id', $groupId);
                // execute the prepared statement to update the database record
                $stmtNewAdmin->execute();
            } catch (PDOException $e) {
                error_log($e->getMessage());
                // 500:internal server error
                return $this->resError($response, 'Database error occurred', 500);
            } 
        } else {
            // Delete the group
            $this->deleteGroup($groupId);

            // delete all messages of group
            $this->deleteAllMessagesFromGroup($groupId);
        }
    }

    //  delete the member from group
    public function removeMemberFromGroup($groupId, $userId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to delete a specific member (identified by user_id) 
            // from a specific group (identified by group_id) in the 'group_members' table
            $stmt = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
            // bind the value of the $groupId variable to the ':group_id' parameter
            $stmt->bindParam(':group_id', $groupId);
            // bind the value of the $userId variable to the ':user_id' parameter
            $stmt->bindParam(':user_id', $userId);
            // execute the prepared statement to delete the record from the database
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 
    }

    // delete the group and remove all members in it
    public function deleteAllMembersAndGroup($groupId) {
        // connect to database
        $db = getDatabaseConnection();

        // Delete all the members from the group
        $this->deleteGroupMembers($groupId);

        // Delete all messages of group
        $this->deleteAllMessagesFromGroup($groupId);

        // Delete the group
        $this->deleteGroup($groupId);
    }

    public function deleteGroup($groupId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to delete a specific group from the 'groups' table using the group's ID
            $stmt = $db->prepare('DELETE FROM groups WHERE group_id = :group_id');
            // bind the value of the $groupId variable to the ':group_id' parameter
            $stmt->bindParam(':group_id', $groupId);
            // execute the prepared statement to delete the group from the database
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        } 
    }

    public function deleteGroupMembers($groupId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to delete all members of a specific group 
            // from the 'group_members' table based on the group's ID
            $stmt = $db->prepare('DELETE FROM group_members WHERE group_id = :group_id');
            // bind the value of the $groupId variable to the ':group_id' parameter
            $stmt->bindParam(':group_id', $groupId);
            // execute the prepared statement to delete the members from the database
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        }
    }

    // delete all messages from a group
    private function deleteAllMessagesFromGroup($groupId) {
        // connect to database
        $db = getDatabaseConnection();

        try {
            // prepare an SQL statement to delete all messages associated with a specific group 
            // from the 'messages' table using the group's ID
            $stmt = $db->prepare("DELETE FROM messages WHERE group_id = :group_id");
            // bind the value of the $groupId variable to the ':group_id' parameter 
            // and specify its type as integer
            $stmt->bindParam(':group_id', $groupId, PDO::PARAM_INT);
            // execute the prepared statement to remove the messages from the database
            $stmt->execute();
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 500:internal server error
            return $this->resError($response, 'Database error occurred', 500);
        }
    }

    // success response
    function jsonResponse($response, $data, $status = 200) {
        $response->getBody()->write(json_encode($data));
        return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store') // non-cacheable
                ->withStatus($status);
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