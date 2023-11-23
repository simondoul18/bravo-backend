var app = require('express')();
const server = require('http').createServer();
const io = require('socket.io')(server);


server.listen(3000, function () {
    console.log('Listening on Port: 3000');
});
var users = [];


io.on('connection', function (socket) {
    
    socket.on("user_connected", function (user_id) {
        users[user_id] = socket.id;
        io.emit('updateUserStatus', users);
        console.log("Hello user connected "+ user_id);
    });

    socket.on('disconnect', function() {
        var i = users.indexOf(socket.id);
        users.splice(i, 1, 0);
        io.emit('updateUserStatus', users);
        console.log(users);
    });

    socket.on('is_typing', function(data){
         if (users[data.receiver_id] != undefined && users[data.receiver_id] != 'undefined' && users[data.receiver_id] != '' && users[data.receiver_id] != null ) {
             socket.broadcast.to(users[data.receiver_id]).emit('typing', data);
        }
       
    });
     socket.on('stopped_typing', function(data){
        if (users[data.receiver_id] != undefined && users[data.receiver_id] != 'undefined' && users[data.receiver_id] != '' && users[data.receiver_id] != null ) {
             socket.broadcast.to(users[data.receiver_id]).emit('typing', data);
        }
       
    });

    socket.on('getMessage', function(data) {
        if (users[data.receiver_id] != undefined && users[data.receiver_id] != 'undefined' && users[data.receiver_id] != '' && users[data.receiver_id] != null) {
            socket.broadcast.to(users[data.receiver_id]).emit('sendToClient',data);
        }
        
    });
  
});