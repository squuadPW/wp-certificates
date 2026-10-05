/**
 * Documentos con el diseño Edusof (solo se carga con el diseño activo, admin/documents.php):
 * - Lista: diálogo de «Eliminar» que dice qué documento y no deja borrar los que tienen certificados o solicitudes.
 * - Editor: editor de código del core (wp.codeEditor) para encabezado, contenido y pie; panel «Datos» con buscador que
 *   inserta la variable donde está el cursor; confirmación al guardar como automático; aviso de cambios sin guardar
 *   entre el formulario del documento y el de «Firmantes del documento».
 * - Variables por firmante numeradas (ADR 0010 de Edusof): en el editor, {{full_name_F2}}, {{signature_F2}}, {{#F2}}…
 *   resaltadas con el color de su firmante (solo en el admin; nunca en el documento).
 */
(function () {
  "use strict";

  const config = window.wpcEdsDocuments || {};
  const text = config.text || {};
  const format = function (template) {
    const args = Array.prototype.slice.call(arguments, 1);
    let i = 0;
    return String(template || "")
      .replace(/%(\d+)\$s/g, function (match, n) {
        return args[Number(n) - 1];
      })
      .replace(/%s/g, function () {
        return args[i++];
      });
  };

  /**
   * Diálogo nativo (dialog.eds-dialog). Devuelve una promesa: true si se confirma. Sin confirmText solo hay «Cerrar».
   * Si el navegador no tiene <dialog>, se usa confirm()/alert().
   */
  function ask(options) {
    if (typeof HTMLDialogElement !== "function") {
      const plain = [].concat(options.body).join("\n\n");
      if (!options.confirmText) {
        window.alert(options.title + "\n\n" + plain);
        return Promise.resolve(false);
      }
      return Promise.resolve(window.confirm(options.title + "\n\n" + plain));
    }
    return new Promise(function (resolve) {
      const dialog = document.createElement("dialog");
      dialog.className = "eds-dialog wpc-eds-dialog";
      const id = "wpc-eds-dialog-" + Date.now();
      dialog.setAttribute("aria-labelledby", id + "-title");
      dialog.setAttribute("aria-describedby", id + "-body");

      const title = document.createElement("h2");
      title.className = "eds-dialog__title";
      title.id = id + "-title";
      title.textContent = options.title;
      const body = document.createElement("div");
      body.className = "eds-dialog__body";
      body.id = id + "-body";
      // Un texto o una lista de textos (un párrafo cada uno)
      [].concat(options.body).forEach(function (text) {
        const paragraph = document.createElement("p");
        paragraph.textContent = text;
        body.appendChild(paragraph);
      });
      const actions = document.createElement("div");
      actions.className = "eds-dialog__actions";

      const cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "eds-btn eds-btn--secondary";
      cancel.textContent = options.confirmText ? text.cancel || "Cancel" : text.close || "Close";
      actions.appendChild(cancel);
      let confirm = null;
      if (options.confirmText) {
        confirm = document.createElement("button");
        confirm.type = "button";
        confirm.className = "eds-btn " + (options.danger ? "eds-btn--danger" : "eds-btn--primary");
        confirm.textContent = options.confirmText;
        actions.appendChild(confirm);
      }
      dialog.appendChild(title);
      dialog.appendChild(body);
      dialog.appendChild(actions);
      document.body.appendChild(dialog);

      let result = false;
      const opener = document.activeElement;
      cancel.addEventListener("click", function () {
        dialog.close();
      });
      if (confirm) {
        confirm.addEventListener("click", function () {
          result = true;
          dialog.close();
        });
      }
      dialog.addEventListener("close", function () {
        dialog.remove();
        if (opener && typeof opener.focus === "function") {
          opener.focus();
        }
        resolve(result);
      });
      dialog.showModal();
      // El foco va a la acción segura (cancelar), no a la definitiva
      cancel.focus();
    });
  }

  // El panel «Firmantes del documento» usa el mismo diálogo (aviso del renumerado de la plantilla, ADR 0010 de Edusof)
  window.wpcEdsAsk = ask;

  /**
   * Resaltado de las variables por firmante numeradas (capa de CodeMirror): cm-fn y cm-fn-<color> según su número
   * (F1 azul, F2 verde, F3 naranja, F4 morado…; se repiten a partir del 7.º, como en el panel).
   */
  const fnColors = Number(config.fnColors) || 6;
  const fnOverlay = {
    token: function (stream) {
      const match = stream.match(/^\{\{(?:(?:full_name|name|last_name|email|id_document|charge|signature)_F|[#^\/]F)([1-9][0-9]?)\}\}/);
      if (match) {
        return "fn fn-" + (((Number(match[1]) - 1) % fnColors) + 1);
      }
      while (stream.next() != null) {
        if (stream.match(/^\{\{/, false)) {
          break;
        }
      }
      return null;
    },
  };

  /* ---------------------------------------------------------------- Lista: eliminar ---------------------------- */
  document.addEventListener("click", function (event) {
    const button = event.target.closest(".wpc-eds-delete");
    if (!button) {
      return;
    }
    const name = button.dataset.title || "";
    const certificates = Number(button.dataset.certificates || 0);
    const requests = Number(button.dataset.requests || 0);
    if (certificates > 0 || requests > 0) {
      ask({
        title: format(text.deleteBlockedTitle, name),
        body: format(text.deleteBlockedBody, String(certificates), String(requests)),
      });
      return;
    }
    ask({
      title: format(text.deleteTitle, name),
      body: text.deleteBody,
      confirmText: text.delete,
      danger: true,
    }).then(function (ok) {
      if (ok) {
        window.location.href = button.dataset.url;
      }
    });
  });

  /* ---------------------------------------------------------------- Editor -------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("wpc-document-form");
    if (!form) {
      return;
    }
    const editors = {};
    let activePart = "content";
    let documentDirty = false;
    let signersDirty = false;
    let submitting = false;

    // 1. Editor de código para encabezado, contenido y pie (si la persona no desactivó el resaltado de sintaxis)
    form.querySelectorAll("textarea.wpc-eds-code").forEach(function (area) {
      const part = area.dataset.part;
      if (config.code && window.wp && wp.codeEditor) {
        const instance = wp.codeEditor.initialize(area, config.code);
        const cm = instance.codemirror;
        editors[part] = cm;
        cm.addOverlay(fnOverlay);
        cm.on("change", function () {
          cm.save();
          documentDirty = true;
          // Para el panel de firmantes, que marca en vivo las variables que ya están en la plantilla
          area.dispatchEvent(new Event("input"));
        });
        cm.on("focus", function () {
          activePart = part;
        });
      } else {
        area.addEventListener("focus", function () {
          activePart = part;
        });
      }
    });

    // 2. Pestañas Encabezado / Contenido / Pie
    const tabs = Array.prototype.slice.call(form.querySelectorAll(".wpc-eds-code-tab"));
    const selectTab = function (tab, focus) {
      tabs.forEach(function (other) {
        const selected = other === tab;
        other.setAttribute("aria-selected", selected ? "true" : "false");
        other.tabIndex = selected ? 0 : -1;
        document.getElementById(other.getAttribute("aria-controls")).hidden = !selected;
      });
      const part = tab.id.replace("wpc-tab-", "");
      activePart = part;
      if (editors[part]) {
        editors[part].refresh();
      }
      if (focus) {
        tab.focus();
      }
    };
    tabs.forEach(function (tab, index) {
      tab.addEventListener("click", function () {
        selectTab(tab, false);
      });
      tab.addEventListener("keydown", function (event) {
        let next = null;
        if (event.key === "ArrowRight") next = tabs[(index + 1) % tabs.length];
        if (event.key === "ArrowLeft") next = tabs[(index - 1 + tabs.length) % tabs.length];
        if (event.key === "Home") next = tabs[0];
        if (event.key === "End") next = tabs[tabs.length - 1];
        if (next) {
          event.preventDefault();
          selectTab(next, true);
        }
      });
    });
    const firstTab = tabs.find(function (tab) {
      return tab.getAttribute("aria-selected") === "true";
    });
    if (firstTab) {
      activePart = firstTab.id.replace("wpc-tab-", "");
    }

    // 3. Panel «Datos»: buscar e insertar donde está el cursor
    const search = document.getElementById("wpc-eds-data-search");
    const status = document.getElementById("wpc-eds-data-status");
    const empty = document.querySelector(".wpc-eds-data__empty");
    const normalize = function (value) {
      return String(value || "")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "");
    };
    if (search) {
      // El buscador está dentro del formulario: Intro no debe guardar el documento
      search.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
          event.preventDefault();
        }
      });
      search.addEventListener("input", function () {
        const query = normalize(search.value.trim());
        let shown = 0;
        document.querySelectorAll(".wpc-eds-data__group").forEach(function (group) {
          let groupShown = 0;
          group.querySelectorAll(".wpc-eds-data__item").forEach(function (item) {
            const match = !query || normalize(item.dataset.search).indexOf(query) !== -1;
            item.hidden = !match;
            if (match) groupShown++;
          });
          group.hidden = groupShown === 0;
          shown += groupShown;
        });
        if (empty) empty.hidden = shown !== 0;
        if (status) status.textContent = query ? format(text.results, String(shown)) : "";
      });
    }
    document.querySelectorAll("button.wpc-eds-var").forEach(function (button) {
      button.addEventListener("click", function () {
        const variable = button.dataset.var;
        const cm = editors[activePart];
        if (cm) {
          cm.replaceSelection(variable);
          cm.focus();
        } else {
          const area = document.getElementById(activePart);
          if (!area) return;
          const start = area.selectionStart;
          const end = area.selectionEnd;
          area.value = area.value.substring(0, start) + variable + area.value.substring(end);
          area.selectionStart = area.selectionEnd = start + variable.length;
          area.dispatchEvent(new Event("input"));
          area.focus();
          documentDirty = true;
        }
        if (status) status.textContent = format(text.inserted, variable);
      });
    });

    // 4. Campos que dependen del formato de hoja y de cómo se entrega
    const paper = document.getElementById("paper_format");
    const typeInputs = form.querySelectorAll('input[name="type"]');
    const currentType = function () {
      const checked = form.querySelector('input[name="type"]:checked');
      return checked ? checked.value : "managed";
    };
    const toggleFields = function () {
      const custom = paper && paper.value === "custom";
      form.querySelectorAll('[data-show-if="custom"]').forEach(function (field) {
        field.hidden = !custom;
      });
      form.querySelectorAll(".wpc-eds-automatic-only").forEach(function (block) {
        block.hidden = currentType() !== "automatic";
      });
    };
    if (paper) paper.addEventListener("change", toggleFields);
    typeInputs.forEach(function (input) {
      input.addEventListener("change", toggleFields);
    });
    toggleFields();

    // 5. Cambios sin guardar: documento y firmantes tienen cada uno su botón de guardar
    const markDirty = function (event) {
      if (event.target !== search) {
        documentDirty = true;
      }
    };
    form.addEventListener("input", markDirty);
    form.addEventListener("change", markDirty);
    const signersForm = document.querySelector("#edusystem-document-signers form");
    if (signersForm) {
      signersForm.addEventListener("change", function () {
        signersDirty = true;
      });
      signersForm.addEventListener("input", function () {
        signersDirty = true;
      });
      signersForm.addEventListener("submit", function (event) {
        if (submitting || !documentDirty) {
          submitting = true;
          return;
        }
        event.preventDefault();
        ask({ title: text.unsavedTitle, body: text.unsavedDocument, confirmText: text.unsavedConfirm }).then(function (ok) {
          if (ok) {
            submitting = true;
            signersForm.submit();
          }
        });
      });
    }
    window.addEventListener("beforeunload", function (event) {
      if (!submitting && (documentDirty || signersDirty)) {
        event.preventDefault();
        event.returnValue = "";
      }
    });

    // 6. Al guardar: cambios de firmantes sin guardar y confirmación de los automáticos (dice a cuántos estudiantes)
    const confirmed = document.getElementById("wpc-eds-automatic-confirmed");
    form.addEventListener("submit", function (event) {
      if (submitting) {
        return;
      }
      Object.keys(editors).forEach(function (part) {
        editors[part].save();
      });
      const steps = [];
      if (signersDirty) {
        steps.push({ title: text.unsavedTitle, body: text.unsavedSigners, confirmText: text.unsavedConfirm });
      }
      if (currentType() === "automatic") {
        const codeInput = document.getElementById("document_identificator");
        const sameCode = !codeInput || codeInput.value.trim().toUpperCase() === String(form.dataset.code || "").toUpperCase();
        const pending = Number(sameCode ? form.dataset.automaticPending : form.dataset.totalStudents) || 0;
        if (pending > 0) {
          steps.push({
            title: text.automaticTitle,
            body: format(sameCode ? text.automaticBody : text.automaticBodyUpTo, String(pending)),
            confirmText: text.automaticConfirm,
            automatic: true,
          });
        }
      }
      if (!steps.length) {
        submitting = true;
        return;
      }
      event.preventDefault();
      const run = function (index) {
        if (index >= steps.length) {
          submitting = true;
          if (typeof form.requestSubmit === "function") {
            form.requestSubmit();
          } else {
            form.submit();
          }
          return;
        }
        ask(steps[index]).then(function (ok) {
          if (!ok) {
            if (confirmed) confirmed.value = "";
            return; // no se guarda
          }
          if (steps[index].automatic && confirmed) {
            confirmed.value = "1";
          }
          run(index + 1);
        });
      };
      run(0);
    });
  });
})();
