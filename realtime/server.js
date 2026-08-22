const express = require("express");
const http = require("http");
const cors = require("cors");
const { Server } = require("socket.io");

const app = express();
const server = http.createServer(app);

app.use(cors());
app.use(express.json());

const io = new Server(server, {
    cors: {
        origin: "*",
        methods: ["GET", "POST"]
    }
});

app.get("/", (req, res) => {
    res.json({
        success: true,
        message: "Carbnb real-time server is running"
    });
});

// ============================================================
// NEW: HTTP endpoint for PHP -> Node.js bridge
//
// PHP cannot emit a Socket.IO event directly the way browser
// JS can (socket.emit(...) only exists client-side, and PHP is
// server-side). So location_tracker.php sends a plain HTTP POST
// here after it has already saved the location to MySQL. This
// endpoint's only job is to take that data and emit it to all
// connected admin browsers via the existing "location_update"
// event - the same event the socket.on("renter_location", ...)
// handler below already emits, so both paths converge on the
// same admin-side handling with no duplicate code needed there.
// ============================================================
app.post("/notify-location", (req, res) => {
    const data = req.body;

    if (
        !data ||
        data.user_id === undefined ||
        data.latitude === undefined ||
        data.longitude === undefined
    ) {
        console.log("Invalid location data from PHP bridge");
        return res.status(400).json({ success: false, message: "Invalid location data" });
    }

    console.log("Location received from PHP:", data);

    io.emit("location_update", {
        user_id: data.user_id,
        booking_id: data.booking_id || null,
        latitude: Number(data.latitude),
        longitude: Number(data.longitude),
        accuracy: Number(data.accuracy || 0),
        recorded_at: data.recorded_at || new Date().toISOString()
    });

    res.json({ success: true });
});

io.on("connection", (socket) => {

    console.log("Client connected:", socket.id);

    socket.on("renter_location", (data) => {

        console.log("Location received:", data);

        if (
            !data ||
            data.user_id === undefined ||
            data.latitude === undefined ||
            data.longitude === undefined
        ) {
            console.log("Invalid location data");
            return;
        }

        io.emit("location_update", {
            user_id: data.user_id,
            booking_id: data.booking_id || null,
            latitude: Number(data.latitude),
            longitude: Number(data.longitude),
            accuracy: Number(data.accuracy || 0),
            recorded_at: data.recorded_at || new Date().toISOString()
        });
    });

    socket.on("disconnect", () => {
        console.log("Client disconnected:", socket.id);
    });

});

const PORT = 3000;

server.listen(PORT, () => {
    console.log(`Carbnb real-time server running on port ${PORT}`);
});