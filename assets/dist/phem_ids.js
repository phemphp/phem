// The temporary ids of a morph.
//
// While a morph runs, the phem controller gives an id that starts with `phem-key:` to an element
// with a `key`, to a bound field and to a component (see keyIds() in phem_controller.js), so the
// morph matches them. The elements lose the id after the morph: a link to it points to nothing.
export const TEMPORARY_ID = "phem-key:"

// The id of `el` that stays after the morph: "" for no id or for a temporary id. It reads the
// attribute, because the `id` property of a `<form>` can be a field with the name "id".
export function realId(el) {
  const id = el.getAttribute("id") ?? ""

  return id.startsWith(TEMPORARY_ID) ? "" : id
}
