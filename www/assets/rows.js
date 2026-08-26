// Shared JS for repeating form rows. Used by block editing (args, inputs)
// and the step page (in, out).
//
// Markup it expects:
//   <tbody id="inputs"> … <tr class="js-row"> … <input name="inputs[0][name]"> … </tr> </tbody>
//   <button type=button data-add="inputs">+ input</button>
//   <button type=button class="js-del-row">×</button>   (inside .js-row)
//
// Plain script, not a module: maxIndex and cloneRow must stay global,
// because the argument-groups script in Block/edit.latte calls them too.

// Rows are never renumbered: a new one gets an index one higher than the
// current maximum, and deleting leaves a gap in the numbering. The server
// sorts the array back with ksort()/array_values(). Renumbering could
// silently swap two values; this way that bug has nowhere to happen.
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
		// cloneNode also copies the id — without removing it, two different
		// fields would share the same id (Nette derives it from the original
		// name).
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
		// Only the index part gets renamed; the rest of the name stays, so a
		// row with multiple fields doesn't end up with all fields under one
		// name.
		box.appendChild(cloneRow(
			rows[rows.length - 1],
			n => n.replace(new RegExp('^' + add + '\\[\\d+]'), add + '[' + next + ']')
		));
	}

	if (e.target.classList && e.target.classList.contains('js-del-row')) {
		const row = e.target.closest('.js-row');
		const box = row.parentElement;
		// The last row stays, otherwise there would be nothing to clone.
		if (box.querySelectorAll('.js-row').length > 1) row.remove();
	}
});
