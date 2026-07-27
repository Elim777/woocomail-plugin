import { copyFile, mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const source = resolve(root, "assets/src");
const output = resolve(root, "assets/build");

await mkdir(output, { recursive: true });
await Promise.all([
    copyFile(resolve(source, "admin-app.js"), resolve(output, "admin-app.js")),
    copyFile(resolve(source, "admin-app.css"), resolve(output, "admin-app.css")),
]);

process.stdout.write("Built OneClick React admin assets.\n");
