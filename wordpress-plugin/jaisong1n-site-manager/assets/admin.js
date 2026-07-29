(() => {
	const escapeHtml = (value) =>
		String(value ?? "")
			.replaceAll("&", "&amp;")
			.replaceAll('"', "&quot;")
			.replaceAll("<", "&lt;")
			.replaceAll(">", "&gt;");

	const renumber = (repeater) => {
		const prefix = repeater.dataset.name;
		repeater.querySelectorAll(".jg-repeater-row").forEach((row, index) => {
			row.querySelectorAll("[data-repeater-key]").forEach((input) => {
				input.name = `${prefix}[${index}][${input.dataset.repeaterKey}]`;
			});
		});
	};

	const createRow = (type, values = {}) => {
		const row = document.createElement("div");
		row.className = "jg-repeater-row";
		row.draggable = true;
		let fields = "";
		if (type === "specs_repeater") {
			fields = `
				<input class="regular-text" type="text" data-repeater-key="label" value="${escapeHtml(values.label)}" placeholder="参数名">
				<input class="regular-text" type="text" data-repeater-key="value" value="${escapeHtml(values.value)}" placeholder="参数值">`;
		} else if (type === "links_repeater") {
			fields = `
				<input class="regular-text" type="text" data-repeater-key="name" value="${escapeHtml(values.name)}" placeholder="链接名称">
				<input class="regular-text" type="url" data-repeater-key="url" value="${escapeHtml(values.url)}" placeholder="https://">
				<select data-repeater-key="type">
					<option value="website">网站</option>
					<option value="certificate">证书</option>
					<option value="project">项目</option>
					<option value="other">其他</option>
				</select>`;
		} else {
			const mediaId = Number(values.mediaId) || 0;
			const imageUrl = escapeHtml(values.imageUrl);
			const title = escapeHtml(values.title || `媒体 #${mediaId}`);
			fields = `
				<input type="hidden" data-repeater-key="mediaId" value="${mediaId}">
				<span class="jg-media-preview">${imageUrl ? `<img src="${imageUrl}" alt="">` : ""}</span>
				<span class="jg-media-label">${title} (#${mediaId})</span>`;
		}
		row.innerHTML = `
			<button type="button" class="button-link jg-drag-handle" aria-label="拖动排序" title="拖动排序">↕</button>
			${fields}
			<button type="button" class="button-link-delete jg-remove-repeater-row">删除</button>`;
		if (type === "links_repeater") {
			row.querySelector("select").value = values.type || "other";
		}
		return row;
	};

	const appendRow = (repeater, values = {}) => {
		const container = repeater.querySelector(".jg-repeater-items");
		container.append(createRow(repeater.dataset.repeaterType, values));
		renumber(repeater);
	};

	const openMediaFrame = ({ multiple, onSelect }) => {
		if (!window.wp?.media) return;
		const frame = window.wp.media({
			title: multiple ? "选择图片" : "选择媒体",
			button: { text: "使用所选媒体" },
			library: { type: "image" },
			multiple,
		});
		frame.on("select", () => onSelect(frame.state().get("selection").toJSON()));
		frame.open();
	};

	document.addEventListener("click", (event) => {
		const addRowButton = event.target.closest(".jg-add-repeater-row");
		if (addRowButton) {
			event.preventDefault();
			appendRow(addRowButton.closest(".jg-repeater"));
			return;
		}

		const addMediaButton = event.target.closest(".jg-add-media-row");
		if (addMediaButton) {
			event.preventDefault();
			const repeater = addMediaButton.closest(".jg-repeater");
			openMediaFrame({
				multiple: true,
				onSelect: (selection) => {
					const existing = new Set(
						[...repeater.querySelectorAll('[data-repeater-key="mediaId"]')].map(
							(input) => Number(input.value),
						),
					);
					selection.forEach((item) => {
						if (existing.has(Number(item.id))) return;
						const imageUrl =
							item.sizes?.thumbnail?.url || item.icon || item.url;
						appendRow(repeater, {
							mediaId: item.id,
							imageUrl,
							title: item.title,
						});
						existing.add(Number(item.id));
					});
				},
			});
			return;
		}

		const removeButton = event.target.closest(".jg-remove-repeater-row");
		if (removeButton) {
			event.preventDefault();
			const repeater = removeButton.closest(".jg-repeater");
			removeButton.closest(".jg-repeater-row").remove();
			renumber(repeater);
			return;
		}

		const selectButton = event.target.closest(".jg-select-media");
		if (selectButton) {
			event.preventDefault();
			const target = document.querySelector(selectButton.dataset.target);
			if (!target) return;
			const multiple = selectButton.dataset.multiple === "1";
			openMediaFrame({
				multiple,
				onSelect: (selection) => {
					target.value = selection.map((item) => item.id).join(",");
					target.dispatchEvent(new Event("change", { bubbles: true }));
				},
			});
			return;
		}

		const clearButton = event.target.closest(".jg-clear-media");
		if (clearButton) {
			event.preventDefault();
			const target = document.querySelector(clearButton.dataset.target);
			if (target) target.value = "";
		}
	});

	document.addEventListener("DOMContentLoaded", () => {
		document.querySelectorAll(".jg-repeater").forEach((repeater) => {
			renumber(repeater);
			if (window.jQuery?.fn?.sortable) {
				window.jQuery(repeater.querySelector(".jg-repeater-items")).sortable({
					handle: ".jg-drag-handle",
					items: ".jg-repeater-row",
					update: () => renumber(repeater),
				});
			}
		});
	});
})();
