// Page Formule : prix des articles achetés séparément, calculé pendant la saisie,
// et case « Passe en cuisine » cochée dès qu'un article de cuisine est ajouté.

import { formatEuros, parseEuros } from "./money.js";

const inputs = [...document.querySelectorAll(".composition input[data-price-cents]")];
const priceInput = document.getElementById("price");
const kitchen = document.getElementById("kitchen");
const summary = document.getElementById("formula-summary");
const separatePrice = document.getElementById("separate-price");
const saving = document.getElementById("saving");

// La case n'est cochée automatiquement que tant que l'administrateur n'y a pas touché
let kitchenTouched = kitchen?.checked ?? true;
kitchen?.addEventListener("change", () => { kitchenTouched = true; });

function quantity(input) {
  const value = Number(input.value);
  return Number.isInteger(value) && value > 0 ? value : 0;
}

function update() {
  const separate = inputs.reduce((sum, input) => sum + quantity(input) * Number(input.dataset.priceCents), 0);
  summary.hidden = separate === 0;
  separatePrice.textContent = formatEuros(separate);

  const price = parseEuros(priceInput.value);
  if (price === null || separate === 0) {
    saving.textContent = "";
  } else if (price < separate) {
    saving.textContent = `La formule fait économiser ${formatEuros(separate - price)} au client.`;
  } else if (price > separate) {
    saving.textContent = `Attention : la formule coûte ${formatEuros(price - separate)} de plus que les articles séparés.`;
  } else {
    saving.textContent = "Même prix que les articles séparés.";
  }

  if (!kitchenTouched && kitchen) {
    kitchen.checked = inputs.some((input) => quantity(input) > 0 && input.dataset.kitchen === "1");
  }
}

for (const input of [...inputs, priceInput]) {
  input?.addEventListener("input", update);
}
if (priceInput) {
  update();
}
