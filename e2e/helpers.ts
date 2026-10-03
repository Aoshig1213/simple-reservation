import { execFileSync, spawn } from "node:child_process";
import fs from "node:fs";
import { PHP, WP_DEV } from "../playwright.config";

const SITE = `${WP_DEV}/site`;

/** WP-CLI を動かす */
export function wp(...args: string[]): string {
  return execFileSync(PHP, [`${PHP.replace(/php\.exe$/, "")}wp-cli.phar`, `--path=${SITE}`, ...args], { encoding: "utf8" }).trim();
}

/** 予約を空にし、設定を見本の値に戻し、メールの記録を消す */
export function reset(settings: Record<string, unknown> = {}): void {
  wp("eval", 'global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sr_reservations");');
  const base = { weekdays: [1, 2, 3, 4, 5, 6], open_time: "10:00", close_time: "18:00", slot_minutes: 60, capacity: 3, max_people: 4,
    days_ahead: 30, min_hours_before: 3, holidays: [], notify_email: "owner@example.com" };
  wp("option", "update", "simple_reservation_settings", JSON.stringify({ ...base, ...settings }), "--format=json");
  fs.rmSync(`${SITE}/wp-content/mail-log.jsonl`, { force: true });
}

export function mails(): { to: string; subject: string; message: string }[] {
  const f = `${SITE}/wp-content/mail-log.jsonl`;
  return fs.existsSync(f) ? fs.readFileSync(f, "utf8").trim().split("\n").filter(Boolean).map((l) => JSON.parse(l)) : [];
}

export function adminCredentials(): { user: string; pass: string } {
  const env = Object.fromEntries(fs.readFileSync(`${WP_DEV}/db.env`, "utf8").split(/\r?\n/).filter(Boolean).map((l) => l.split(/=(.*)/s).slice(0, 2)));
  return { user: env.WP_ADMIN_USER, pass: env.WP_ADMIN_PASSWORD };
}

/** 同じ枠に、n 個の PHP プロセスから同時に予約する。成功した数を返す */
export async function concurrentBook(n: number, date: string, time: string, people: number): Promise<string[]> {
  const code = `require '${SITE}/wp-load.php';
    $r = SimpleReservation\\Repository::book(['date'=>'${date}','time'=>'${time}','people'=>${people},'name'=>'同時'.getmypid(),'email'=>'c@example.com','phone'=>'0900000000','note'=>''], SimpleReservation\\Plugin::settings()->capacity);
    echo is_int($r) ? 'ok' : $r;`;
  const run = () => new Promise<string>((resolve) => {
    const p = spawn(PHP, ["-r", code]);
    let out = "";
    p.stdout.on("data", (d) => (out += d));
    p.on("close", () => resolve(out.trim().split("\n").pop() ?? ""));
  });
  return Promise.all(Array.from({ length: n }, run));
}
