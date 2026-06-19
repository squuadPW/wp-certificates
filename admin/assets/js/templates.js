document.addEventListener("DOMContentLoaded", (event) => {
  let all_textareas = document.querySelectorAll(".content-textarea");

  all_textareas.forEach((element) => {
    element.addEventListener("input", function (ev) {
      const valor = ev.target.value;
    });
  });
});
