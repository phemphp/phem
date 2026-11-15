# Phem gaps found while building phem/ui-base

Each item is a candidate fix in phem core. The bundle does not work around them.

Status (2026-11-15): phem v0.4.1 fixes items 1 to 4 and 7 to 17. This file lists only the open
items, with their first numbers. The git history of this file has the fixed items.

5. **Menu checkbox and radio items are server state only.** Base UI toggles them in the browser
   (`defaultChecked`). The bundle takes `checked` from the server, with an `onClick` handler.

6. **Toast has no title part.** Base UI toasts have a title and a description. `phem-toast` has
   only the message (`[data-phem-toast-description]`). Base UI also has `data-behind` on the content.
