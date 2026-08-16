// Sdílený JS pro opakující se řádky formuláře. Používá ho editace kamene
// (args, inputs) i stránka kroku (in, out).
//
// Značkování, které očekává:
//   <tbody id="inputs"> … <tr class="js-row"> … <input name="inputs[0][name]"> … </tr> </tbody>
//   <button type=button data-add="inputs">+ řádek</button>
//   <button type=button class="js-del-row">×</button>   (uvnitř .js-row)
//
// Klasický skript, ne modul: maxIndex a cloneRow musí zůstat globální,
// protože je volá i skript skupin argumentů v Block/edit.latte.

// Řádky se nikdy nepřečíslovávají: nový dostane index o jedna vyšší, než je
// současné maximum, a smazání nechá v číslování díru. Server pole srovná
// přes ksort()/array_values(). Přečíslovávání by mohlo tiše prohodit dvě
// hodnoty; takhle ta chyba nemá kde vzniknout.
const maxIndex = (nodes, re) => {
	let max = -1;
	nodes.forEach(n => {
		const m = re.exec(n.getAttribute('name') || '');
		if (m) max = Math.max(max, parseInt(m[1], 10));
	});
	return max;
};

const cloneRow = (row, rename) => {
	const copy = row.cloneNode(true);
	copy.querySelectorAll('input, select').forEach(i => {
		i.setAttribute('name', rename(i.getAttribute('name')));
		// cloneNode kopíruje i id — bez odebrání by měla dvě různá pole
		// stejné id (Nette ho odvozuje z původního jména).
		i.removeAttribute('id');
		if (i.type === 'checkbox') i.checked = false;
		else if (i.tagName === 'SELECT') i.selectedIndex = 0;
		else i.value = '';
	});
	return copy;
};

document.addEventListener('click', e => {
	const add = e.target.getAttribute && e.target.getAttribute('data-add');

	if (add) {
		const box = document.getElementById(add);
		const rows = box.querySelectorAll('.js-row');
		const prefix = new RegExp('^' + add + '\\[(\\d+)]');
		const next = maxIndex(box.querySelectorAll('input, select'), prefix) + 1;
		// Přejmenuje se jen indexová část; zbytek jména zůstane, aby řádek
		// s víc poli nedostal všechna pole pod jedním jménem.
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(new RegExp('^' + add + '\\[\\d+]'), add + '[' + next + ']')
		));
	}

	if (e.target.classList && e.target.classList.contains('js-del-row')) {
		const row = e.target.closest('.js-row');
		const box = row.parentElement;
		// Poslední řádek zůstane, jinak by nebylo co klonovat.
		if (box.querySelectorAll('.js-row').length > 1) row.remove();
	}
});
