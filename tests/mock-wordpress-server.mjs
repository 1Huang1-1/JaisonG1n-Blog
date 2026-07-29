import { createServer } from "node:http";

const port = Number.parseInt(process.env.MOCK_WORDPRESS_PORT || "43123", 10);

const server = createServer((request, response) => {
	const url = new URL(request.url || "/", `http://${request.headers.host}`);
	if (url.pathname !== "/wp-json/wp/v2/posts") {
		response.writeHead(404, { "content-type": "application/json" });
		response.end(JSON.stringify({ code: "rest_no_route" }));
		return;
	}

	response.writeHead(200, {
		"content-type": "application/json; charset=utf-8",
		"x-wp-total": "0",
		"x-wp-totalpages": "1",
	});
	response.end("[]");
});

server.listen(port, "127.0.0.1", () => {
	process.stdout.write(`mock-wordpress-ready:${port}\n`);
});

for (const signal of ["SIGINT", "SIGTERM"]) {
	process.on(signal, () => server.close(() => process.exit(0)));
}
