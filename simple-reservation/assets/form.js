// 日付を選んだら、その日の空き枠をすぐ表示する（JavaScript がなくても「表示」ボタンで切り替えられる）
document.querySelectorAll('.sr').forEach(function (root) {
	root.classList.add('sr-js');
	root.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
		sel.addEventListener('change', function () { sel.form.submit(); });
	});
	// 二重送信を防ぐ
	root.querySelectorAll('form.sr-book').forEach(function (form) {
		form.addEventListener('submit', function () {
			var btn = form.querySelector('.sr-submit');
			if (btn) { btn.disabled = true; btn.textContent = '送信しています…'; }
		});
	});
});
