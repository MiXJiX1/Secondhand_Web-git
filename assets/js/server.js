const http = require('http');
const fs = require('fs');
const path = require('path');
const socketIo = require('socket.io');

// สร้างเซิร์ฟเวอร์ HTTP
const server = http.createServer((req, res) => {
    const filePath = req.url === '/' ? 'index.html' : req.url;
    const fullPath = path.join(__dirname, filePath);

    fs.readFile(fullPath, (err, data) => {
        if (err) {
            res.writeHead(404);
            res.end('Not Found');
        } else {
            const ext = path.extname(fullPath).toLowerCase();
            const contentType = ext === '.html' ? 'text/html' :
                                ext === '.js' ? 'application/javascript' : 'text/plain';
            res.writeHead(200, { 'Content-Type': contentType });
            res.end(data);
        }
    });
});

// ตั้งค่า Socket.IO
const io = socketIo(server);

io.on('connection', (socket) => {
    console.log('🟢 ผู้ใช้เชื่อมต่อแล้ว');

    socket.on('join_room', (room) => {
        socket.join(room);
        console.log(`🟡 เข้าห้อง: ${room}`);
    });

    socket.on('chat', (data) => {
        io.to(data.room).emit('chat', {
            name: data.name,
            message: data.message
        });
    });

    socket.on('disconnect', () => {
        console.log('🔴 ผู้ใช้ยกเลิกการเชื่อมต่อ');
    });
});

const PORT = 5000;
server.listen(PORT, () => {
    console.log(`🚀 Server is running at http://localhost:${PORT}`);
});
