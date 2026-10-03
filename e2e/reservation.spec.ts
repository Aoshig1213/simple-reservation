import { expect, test, type Page } from "@playwright/test";
import { adminCredentials, concurrentBook, mails, reset, wp } from "./helpers";

test.describe.configure({ mode: "serial" });

// 予約フォームの2番目の日（締め切りに引っかからないよう、今日は避ける）を開く
async function openSecondDay(page: Page): Promise<string> {
  await page.goto("/reserve/");
  const date = await page.locator("#sr-date option").nth(1).getAttribute("value");
  await page.locator("#sr-date").selectOption(date!);
  await page.waitForURL(new RegExp(`sr_date=${date}`));
  return date!;
}

async function fill(page: Page, o: { time: string; people: string; name: string }) {
  await page.locator(`input[name=time][value="${o.time}"]`).check();
  await page.locator("#sr-people").selectOption(o.people);
  await page.locator("#sr-name").fill(o.name);
  await page.locator("#sr-email").fill("hanako@example.com");
  await page.locator("#sr-phone").fill("０９０－１２３４－５６７８");
}

test.beforeAll(() => reset());

test("予約すると受付の表示とメールが出る", async ({ page }) => {
  const date = await openSecondDay(page);
  await expect(page.locator(".sr-slot")).toHaveCount(8);
  await fill(page, { time: "10:00", people: "2", name: "山田 花子" });
  await page.locator("#sr-note").fill("初めてです。\n腰が痛みます。");
  await page.getByRole("button", { name: "この内容で予約する" }).click();
  await expect(page.getByText("ご予約を受け付けました。")).toBeVisible();
  await expect(page.getByText(/10:00\s+2名/)).toBeVisible();

  const m = mails();
  expect(m.map((x) => x.to)).toEqual(["hanako@example.com", "owner@example.com"]);
  expect(m[0].subject).toContain("ご予約を受け付けました");
  expect(m[1].message).toContain("電話：090-1234-5678"); // 全角で入れても半角で届く
  expect(m[1].message).toContain("初めてです。\n腰が痛みます。");

  // 同じ日をもう一度開くと、10:00 の残りが 1名になっている
  await page.goto(`/reserve/?sr_date=${date}`);
  await expect(page.locator('label[for="sr-t-1000"] .sr-left')).toHaveText("残り1名");
});

test("入力ミスは項目ごとに知らせ、入れた内容は消さない", async ({ page }) => {
  await openSecondDay(page);
  await page.locator("#sr-name").fill("佐藤 一郎");
  await page.locator("#sr-email").fill("not-an-email");
  await page.getByRole("button", { name: "この内容で予約する" }).click();
  await expect(page.getByText("入力内容をご確認ください。")).toBeVisible();
  await expect(page.getByText("この日時は予約を受け付けていません。別の枠を選んでください")).toBeVisible();
  await expect(page.getByText("メールアドレスを正しく入力してください")).toBeVisible();
  await expect(page.getByText("電話番号を入力してください")).toBeVisible();
  await expect(page.locator("#sr-name")).toHaveValue("佐藤 一郎");
  await expect(page.locator("#sr-email")).toHaveValue("not-an-email");
});

test("残りより多い人数は受け付けず、埋まった枠は選べなくなる", async ({ page }) => {
  const date = await openSecondDay(page);
  await fill(page, { time: "10:00", people: "2", name: "鈴木 次郎" }); // 残り1名の枠に2名
  await page.getByRole("button", { name: "この内容で予約する" }).click();
  await expect(page.getByText(/満席になりました/)).toBeVisible();

  await page.goto(`/reserve/?sr_date=${date}`);
  await fill(page, { time: "10:00", people: "1", name: "鈴木 次郎" });
  await page.getByRole("button", { name: "この内容で予約する" }).click();
  await expect(page.getByText("ご予約を受け付けました。")).toBeVisible();

  await page.goto(`/reserve/?sr_date=${date}`);
  await expect(page.locator('label[for="sr-t-1000"] .sr-left')).toHaveText("満席");
  await expect(page.locator("#sr-t-1000")).toBeDisabled();
});

test("合言葉（nonce）のない送信は断る", async ({ request }) => {
  const res = await request.post("/wp-admin/admin-post.php", { form: { action: "simple_reservation_book", date: "2026-12-01", time: "10:00" } });
  expect(res.status()).toBe(403);
});

test("同時に10件申し込んでも、定員3の枠には3件しか入らない", async () => {
  reset({ capacity: 3 });
  const date = wp("eval", "echo current_datetime()->modify('+1 day')->format('Y-m-d');");
  const day = new Date(date + "T00:00:00+09:00").getDay();
  const target = day === 0 ? wp("eval", "echo current_datetime()->modify('+2 day')->format('Y-m-d');") : date; // 日曜は休み
  const results = await concurrentBook(10, target, "15:00", 1);
  expect(results.filter((r) => r === "ok")).toHaveLength(3);
  expect(results.filter((r) => r === "full")).toHaveLength(7);
});

test("管理画面で予約を確かめ、キャンセルすると空きが戻る", async ({ page }) => {
  reset();
  // 1件入れておく（名前が = で始まるのは、CSV の数式対策の確認用）
  const date = await openSecondDay(page);
  await fill(page, { time: "11:00", people: "3", name: "=1+1 テスト" });
  await page.getByRole("button", { name: "この内容で予約する" }).click();
  await expect(page.getByText("ご予約を受け付けました。")).toBeVisible();

  const { user, pass } = adminCredentials();
  await page.goto("/wp-login.php");
  await page.locator("#user_login").fill(user);
  await page.locator("#user_pass").fill(pass);
  await page.locator("#wp-submit").click();
  await page.goto("/wp-admin/admin.php?page=simple-reservation");
  const row = page.locator("table.widefat tbody tr").first();
  await expect(row).toContainText("=1+1 テスト");
  await expect(row).toContainText("3名");
  await expect(row).toContainText("受付済み");

  // CSV：BOM 付きで、= で始まる値は ' を付けて数式として動かないようにする
  const [download] = await Promise.all([page.waitForEvent("download"), page.getByRole("link", { name: "CSV で書き出す" }).click()]);
  const csv = await (await download.createReadStream()).toArray().then((b) => Buffer.concat(b).toString("utf8"));
  expect(csv.charCodeAt(0)).toBe(0xfeff);
  expect(csv).toContain("'=1+1 テスト");

  page.once("dialog", (d) => d.accept());
  await row.getByRole("button", { name: "キャンセルにする" }).click();
  await expect(page.getByText("予約の状態を変更しました。")).toBeVisible();
  await expect(page.locator("table.widefat tbody tr").first()).toContainText("キャンセル");

  await page.goto(`/reserve/?sr_date=${date}`);
  await expect(page.locator('label[for="sr-t-1100"] .sr-left')).toHaveText("残り3名");
});

test("ログインしていない人は管理画面の操作ができない", async ({ request }) => {
  const res = await request.post("/wp-admin/admin-post.php", { form: { action: "sr_status", id: "1", status: "cancelled" }, maxRedirects: 0 });
  // ログインしていない人向けの受け口は作っていないので、WordPress が 400 で断る
  expect([400, 403]).toContain(res.status());
  expect(wp("eval", 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sr_reservations WHERE id = 1 AND status = \'cancelled\'");')).toBe("1"); // 上のテストでキャンセルした1件のまま
});
