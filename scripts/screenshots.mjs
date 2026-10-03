// README 用の画面写真を撮る（手元の WordPress を起動しておく: php -S localhost:8088 dev/router.php）
import { chromium } from "@playwright/test";
import fs from "node:fs";
import { execFileSync } from "node:child_process";

const WP_DEV = process.env.WP_DEV ?? "C:/work/tools/wp-dev";
const PHP = process.env.PHP_BIN ?? "C:/work/tools/php83/php.exe";
const base = "http://localhost:8088";
const out = "docs/images";
fs.mkdirSync(out, { recursive: true });
const wp = (...a) => execFileSync(PHP, [PHP.replace(/php\.exe$/, "wp-cli.phar"), `--path=${WP_DEV}/site`, ...a], { encoding: "utf8" });

// 見本の予約を入れておく（名前はすべて架空）
wp("eval", `global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sr_reservations");
  $d = current_datetime()->modify('+1 day'); if ($d->format('w') === '0') $d = $d->modify('+1 day'); $day = $d->format('Y-m-d');
  foreach ([['10:00',3,'山田 花子'],['11:00',1,'佐藤 一郎'],['14:00',2,'鈴木 美咲'],['16:00',1,'高橋 健']] as [$t,$n,$name])
    SimpleReservation\\Repository::book(['date'=>$day,'time'=>$t,'people'=>$n,'name'=>$name,'email'=>'sample@example.com','phone'=>'090-0000-0000','note'=>$name==='鈴木 美咲'?'肩こりがひどいです':''], 3);
  echo $day;`);
const env = Object.fromEntries(fs.readFileSync(`${WP_DEV}/db.env`, "utf8").split(/\r?\n/).filter(Boolean).map((l) => l.split(/=(.*)/s)));

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 900 }, locale: "ja-JP" });
await page.goto(`${base}/reserve/`);
const date = await page.locator("#sr-date option").nth(1).getAttribute("value");
await page.goto(`${base}/reserve/?sr_date=${date}`);
await page.locator('input[name=time][value="15:00"]').check();
await page.locator("#sr-people").selectOption("2");
await page.locator("#sr-name").fill("田中 さくら");
await page.locator("#sr-email").fill("sakura@example.com");
await page.locator("#sr-phone").fill("080-1234-5678");
await page.locator(".sr").screenshot({ path: `${out}/form.png` });
await page.getByRole("button", { name: "この内容で予約する" }).click();
await page.locator(".sr-ok").waitFor();
await page.locator(".sr").screenshot({ path: `${out}/done.png` });

await page.goto(`${base}/wp-login.php`);
await page.locator("#user_login").fill(env.WP_ADMIN_USER);
await page.locator("#user_pass").fill(env.WP_ADMIN_PASSWORD);
await page.locator("#wp-submit").click();
await page.goto(`${base}/wp-admin/admin.php?page=simple-reservation`);
await page.screenshot({ path: `${out}/admin-list.png` });
await page.goto(`${base}/wp-admin/admin.php?page=simple-reservation-settings`);
await page.screenshot({ path: `${out}/admin-settings.png`, fullPage: true });
await browser.close();
console.log("ok");
