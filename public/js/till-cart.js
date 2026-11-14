// Panier de la caisse : quantités, consigne et total, sans affichage.
// Le total calculé ici sert seulement à l'affichage : le serveur recalcule tout.

export const MAX_QUANTITY = 99;
export const MAX_LINES = 50;

export class Cart {
  constructor() {
    /** @type {Map<number, number>} quantité par identifiant d'article, dans l'ordre d'ajout */
    this.quantities = new Map();
    this.depositReturns = 0;
  }

  /** @returns {boolean} false si la limite est atteinte */
  add(productId) {
    const quantity = this.quantities.get(productId) ?? 0;
    if (quantity >= MAX_QUANTITY || (quantity === 0 && this.quantities.size >= MAX_LINES)) {
      return false;
    }
    this.quantities.set(productId, quantity + 1);
    return true;
  }

  remove(productId) {
    const quantity = (this.quantities.get(productId) ?? 0) - 1;
    if (quantity > 0) {
      this.quantities.set(productId, quantity);
    } else {
      this.quantities.delete(productId);
    }
  }

  changeReturns(delta) {
    this.depositReturns = Math.min(MAX_QUANTITY, Math.max(0, this.depositReturns + delta));
  }

  isEmpty() {
    return this.quantities.size === 0 && this.depositReturns === 0;
  }

  clear() {
    this.quantities.clear();
    this.depositReturns = 0;
  }

  /** Retire les articles qui ne sont plus au menu (menu rechargé). */
  keepOnly(menu) {
    for (const id of this.quantities.keys()) {
      if (!menu.byId.has(id)) {
        this.quantities.delete(id);
      }
    }
    if (menu.depositCents === 0) {
      this.depositReturns = 0;
    }
  }

  /**
   * Lignes à afficher, avec les mêmes règles que le serveur :
   * une consigne par article « avec consigne », un retour négatif par gobelet rendu.
   */
  lines(menu) {
    const lines = [];
    let deposits = 0;
    for (const [id, quantity] of this.quantities) {
      const product = menu.byId.get(id);
      lines.push({ kind: "article", productId: id, label: product.name, unitPriceCents: product.priceCents, quantity });
      if (product.hasDeposit) {
        deposits += quantity;
      }
    }
    if (menu.depositCents > 0 && deposits > 0) {
      lines.push({ kind: "consigne", label: "Consigne", unitPriceCents: menu.depositCents, quantity: deposits });
    }
    if (this.depositReturns > 0) {
      lines.push({ kind: "retour_consigne", label: "Retour de consigne", unitPriceCents: -menu.depositCents, quantity: this.depositReturns });
    }
    return lines.map((line) => ({ ...line, totalCents: line.unitPriceCents * line.quantity }));
  }

  total(menu) {
    return this.lines(menu).reduce((sum, line) => sum + line.totalCents, 0);
  }

  hasKitchenItem(menu) {
    return [...this.quantities.keys()].some((id) => menu.byId.get(id)?.goesToKitchen);
  }

  /** Au moins un article remis au comptoir (boissons…), hors consigne. */
  hasCounterItem(menu) {
    return [...this.quantities.keys()].some((id) => !menu.byId.get(id)?.goesToKitchen);
  }

  /** Lignes envoyées au serveur : identifiants et quantités, aucun prix. */
  payloadLines() {
    return [...this.quantities].map(([productId, quantity]) => ({ productId, quantity }));
  }

  /**
   * Stock restant affiché, panier en cours déduit (information seulement :
   * un article épuisé reste vendable). null = non compté.
   * Composants d'une formule : [{productId, quantity}].
   */
  remainingStock(menu, product) {
    const used = new Map();
    const use = (id, quantity) => used.set(id, (used.get(id) ?? 0) + quantity);
    for (const [id, quantity] of this.quantities) {
      const sold = menu.byId.get(id);
      if (sold.components.length) {
        for (const part of sold.components) {
          use(part.productId, part.quantity * quantity);
        }
      } else {
        use(id, quantity);
      }
    }
    const left = (id) => {
      const stock = menu.byId.get(id)?.remainingStock;
      return stock === null || stock === undefined ? null : stock - (used.get(id) ?? 0);
    };
    if (!product.components.length) {
      return left(product.id);
    }
    // Nombre de formules encore possibles avec les articles comptés
    const possible = product.components
      .map((part) => ({ stock: left(part.productId), quantity: part.quantity }))
      .filter((part) => part.stock !== null)
      .map((part) => Math.floor(part.stock / part.quantity));
    return possible.length ? Math.min(...possible) : null;
  }
}

/** Menu reçu de GET /api/menu, avec un index par identifiant. */
export function indexMenu(data) {
  return {
    ...data,
    depositCents: data.event.depositCents,
    byId: new Map(data.products.map((product) => [product.id, product])),
  };
}

/** UUID v4 ; crypto.getRandomValues fonctionne aussi hors HTTPS, contrairement à crypto.randomUUID. */
export function newUuid() {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
