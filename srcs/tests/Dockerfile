FROM postman/newman

WORKDIR /etc/newman

COPY ./chat.postman_collection.json ./

CMD ["run", "./chat.postman_collection.json"]