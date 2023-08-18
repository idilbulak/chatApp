# Chat Backend Project

## Introduction
This project is a chat application backend written in PHP with Slim Framework that utilizes Docker for containerization.

## Prerequisites
- Docker and Docker Compose should be installed on your machine.

## Getting Started
Build the Project: make
Start the Project: make up
Stop the Project: make down
Rebuild the Project: make re
Clean Up: make clean

## Project Overview

### Application - Chat
My chat application runs inside a Docker container, which is accessible through your web browser at `localhost:8080`. This container is named `chat`.

With this application, users can create new groups. The individual who creates a group automatically assumes the role of its admin. The admin has the flexibility to assign any name to their group. Users are free to join any group, read messages from the groups they belong to, and contribute by posting new messages. It's important to note that only members of a group can send messages and view the group's discussions. There's a maximum content length of 1000 for messages, and the application checks for the use of special characters. I've also implemented safeguards against SQL injections. Should users decide, they can leave groups at any time. If an admin chooses to leave their group, administrative rights will transfer to another member. If no other members are present, the group will be automatically deleted. Lastly, admins retain the right to delete their group and all associated messages.

- **Volumes**: The application binds a volume named `db`, which is mapped to `/var/www/html` inside the container. This volume ensures persistent storage for our chat application, meaning your data remains intact even if the container is destroyed.
- **Ports**: The service exposes port 8080 and maps it to port 8080 on the host machine. This allows you to access the chat application via `localhost:8080` on your web browser.

### Tests Container
I also have a separate container for running tests, named `tests` by pulling postman/newman. This container depends on the `app` service, ensuring that tests can interact with the main chat application.

### Persistent Data Storage
For data persistence, I've leveraged Docker volumes. The volume named `db` is bound to `/$HOME/data/chat` on the host machine. This means any data generated or used by the chat application will be stored at `$HOME/data/chat` on your local system. This directory serves as a safeguard against data loss, as the data remains stored even if the Docker container is terminated.

## API Endpoints

#### **GET** `/groups`
- **Description**: List all groups.
- **Controller**: `GroupController:getAll`
- **Request Body**: None

#### **POST** `/groups`
- **Description**: Create a new group.
- **Controller**: `GroupController:create`
- **Request Body**: 
  ```json
  {
      "group_name": "Group Name",
      "user_id": "User Id of the user who wants to create it"
  }

#### **POST** `/groups/{group_id}/join`
- **Description**: Join a specific group by its ID.
- **Controller**: `GroupController:join`
- **Request Body**: 
  ```json
  {
      "user_id": "User Id of the user who wants to join"
  }

#### **POST** `/groups/{group_id}/leave`
- **Description**: Leave a specific group by its ID.
- **Controller**: `GroupController:leave`
- **Request Body**: 
  ```json
  {
      "user_id": "User Id of the user who wants to leave"
  }

#### **DELETE** `/groups/{group_id}`
- **Description**: Delete a specific group by its ID.
- **Controller**: `GroupController:delete`
- **Request Body**: None

#### **POST** `/groups/{group_id}/messages`
- **Description**: Send a message to a specific group by its ID.
- **Controller**: `GroupController:sendMessage`
- **Middleware**: `validateContent`
- **Request Body**: 
  ```json
  {
      "user_id": "User Id of the user who wants to send a message",
      "content": "message itself"
  }

#### **GET** `/groups/{group_id}/messages`
- **Description**: Get messages from a specific group by its ID.
- **Controller**: `GroupController:getMessages`
- **Request Body**: None

#### **Default Route** `ANY /{route}`
- **Description**: Block any other unspecified route and return a "Not Found" message.
- **Response**: `404 Not Found`

## Database Schema

My application uses a SQLite database, comprising four main tables:

### `users` Table
- **`user_id`**: The primary key for users. It is an integer value.

### `groups` Table
- **`group_id`**: The primary key for groups. It is an integer value.
- **`group_name`**: A text field that stores the name of the group. It is mandatory.
- **`group_admin`**: An integer that references the user_id from the users table. This field identifies the admin of the group.

### `group_members` Table
- **`group_id`**: A foreign key that references the group_id from the groups table.
- **`user_id`**: A foreign key that references the user_id from the users table.
PRIMARY KEY (group_id, user_id): This table uses a composite primary key composed of group_id and user_id.

### `messages` Table
- **`message_id`**: The primary key for messages. It is an integer value.
- **`group_id`**: A foreign key that references the group_id from the groups table.
- **`user_id`**: A foreign key that references the user_id from the users table.
- **`content`**: A text field that stores the content of the message.
- **`timestamp`**: A datetime field that stores the timestamp of when the message was posted. It defaults to the current timestamp.

## Contact
Idil BULAK
bulakidil@gmail.com