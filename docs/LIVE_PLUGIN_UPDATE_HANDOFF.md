# Live Plugin Update Handoff

Context: while updating zazacaogren.com from LL Tools 6.3.0 to 6.3.3 on 2026-05-19, the Site Tools "Dev branch builds" update check kept reporting "No update detected" even after the dev branch had a newer version.

What happened:

- Authenticated REST worked for read/write corpus operations and for checking the installed plugin list.
- The Site Tools manual update URL (`admin-post.php?action=ll_tools_check_plugin_update`) only worked reliably with normal browser-like wp-admin headers/referrer. Without those headers, the hidden-login/admin routing returned a theme 404.
- Once the manual check ran successfully, it still reported no update.
- The likely root cause was the live 6.3.0 updater configuration: release-asset enforcement was applied even when the selected update branch was `dev`, which defeated the intended branch-based dev update path.
- The 6.3.3 release changed `ll_tools_configure_update_checker()` so release assets are required only for `main`; `dev` now returns immediately after setting the branch.
- Because the broken code was already live, it could not bootstrap its own update. The update was completed through the normal WordPress upload flow: upload the packaged plugin zip, then confirm "Replace current with uploaded".

Recommendation:

- Do not build a custom REST plugin installer unless repeated live updates prove WordPress' native upload/replace flow is too slow. It is safer to keep plugin replacement in WordPress core.
- It would be worth improving Site Tools diagnostics: after a manual update check, show the selected branch, current version, detected remote version if any, and any Plugin Update Checker API errors from `getLastRequestApiErrors()`. That would make future "No update detected" states debuggable without guessing.
- Future dev-channel updates from 6.3.3+ should be tested through Site Tools first. If they still fail to detect, check PUC strategy selection and whether the GitHub token/private-repo access is available on the live site.
