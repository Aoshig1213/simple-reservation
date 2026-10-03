import { defineConfig } from "@playwright/test";

// 手元の WordPress（PHP の組み込みサーバー）に対して動かす。場所は環境変数で変えられる（README 参照）
export const WP_DEV = process.env.WP_DEV ?? "C:/work/tools/wp-dev";
export const PHP = process.env.PHP_BIN ?? "C:/work/tools/php83/php.exe";
const PORT = 8088;

export default defineConfig({
  testDir: "e2e",
  workers: 1,
  fullyParallel: false,
  timeout: 60_000,
  use: { baseURL: `http://localhost:${PORT}`, locale: "ja-JP", timezoneId: "Asia/Tokyo" },
  webServer: {
    command: `"${PHP}" -S localhost:${PORT} dev/router.php`,
    url: `http://localhost:${PORT}/wp-login.php`,
    reuseExistingServer: true,
    env: { WP_ROOT: `${WP_DEV}/site` },
    timeout: 60_000,
  },
});
