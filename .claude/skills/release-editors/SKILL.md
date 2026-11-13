---
name: release-editors
description: Release the Phem editor plugins. Publishes the VS Code extension to the Visual Studio Marketplace, then the PhpStorm plugin to the JetBrains Marketplace, with the version, changelog, build and verification checks of each one.
disable-model-invocation: true
---

# Release the editor plugins

Release the VS Code extension first, then the PhpStorm plugin. Do all the steps for one plugin
before you start the next plugin. Run all the commands in the repository root.

This skill publishes to public marketplaces. A published version cannot be removed or
republished with the same number. Ask the user before each publish, and stop when a check fails.

Do not commit or push without the explicit confirmation of the user. Do not read, print or ask
for a token value. The tokens stay in the macOS Keychain, and only the publish commands read them.

## 1. Check the repository

1. Run `git status --short` and `git status -sb`. The branch must be `main`, the working tree
   must be clean, and `main` must match `origin/main`. Run `git fetch` first.
2. If one of these is not true, stop and tell the user. A release must come from the pushed
   `main`, so that the published code is on GitHub.

## 2. Find what to release

For each plugin, get the local version and the published version:

| Plugin  | Local version                                                       | Published version |
|---------|---------------------------------------------------------------------|-------------------|
| VS Code | `"version"` in `editors/vscode/package.json`                        | `npx --yes @vscode/vsce@4.0.0 show yceruto.phem --json \| node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s).versions[0].version))'` |
| PhpStorm | `pluginVersion` in `editors/phpstorm/gradle.properties`            | `curl -s 'https://plugins.jetbrains.com/plugins/list?pluginId=phem' \| grep -o '<version>[^<]*' \| head -n 1 \| cut -c10-` |

Then decide for each plugin:

- **The local version is higher:** release it.
- **The two versions are the same:** look for changes that are not released. Run
  `git log --oneline <commit>..HEAD -- <paths>`. `<commit>` is the last commit that changed the
  version line (`git log -1 --format=%H -S'<version>' -- <version file>`). The paths are:
  - VS Code: `editors/vscode/ editors/server/ compiler/`
  - PhpStorm: `editors/phpstorm/ editors/server/ compiler/`

  Ignore the `Prepare release <version> [skip ci]` commits. They only set the version of the
  compiler. If there are no other changes, skip the plugin. If there are changes, show them, and propose a new
  version and the changelog items with the rules of "Plugin version" in `CLAUDE.md`. Make the
  changes only after the user agrees, then stop. The user must commit and push them before the
  release.
- **The PhpStorm published version is empty:** the plugin is not on the Marketplace yet. A token
  cannot create a plugin. Tell the user to upload the first version in the web UI (see
  "Publish" in `editors/phpstorm/README.md`), and skip the PhpStorm plugin.

Show the user a table: each plugin, its published version, its local version, and the action
(release or skip).

## 3. Check the changelog

For each plugin to release, read the `## <local version>` section:

- VS Code: `editors/vscode/CHANGELOG.md`
- PhpStorm: `editors/phpstorm/CHANGELOG.md`

The section must exist and have a list of the changes. Compare it with
`git log --oneline <commit>..HEAD -- <paths>` (from step 2). If a change that a user can see is
missing, propose the item and stop, as in step 2.

## 4. Release the VS Code extension

1. Build the extension:

   ```sh
   make vscode
   ```

   The build writes `editors/vscode/phem-<version>.vsix`.
2. Make sure that `vsce` is signed in for the `yceruto` publisher. Run `vsce ls-publishers`. If
   `yceruto` is not in the list, tell the user to run `vsce login yceruto` in their own terminal
   with their Azure DevOps token, and wait.
3. Show the user the version and the changelog section. Ask: publish the VS Code extension
   `<version>`? Wait for a clear yes.
4. Publish the file that the build made. Do not let `vsce` build it again:

   ```sh
   vsce publish --packagePath editors/vscode/phem-<version>.vsix
   ```

5. Get the published version again (step 2). The Marketplace can need some minutes to show it.
   Tell the user the result and the link: https://marketplace.visualstudio.com/items?itemName=yceruto.phem

If the publish fails, show the output and stop. Do not start the PhpStorm release.

## 5. Release the PhpStorm plugin

1. Show the user the version and the changelog section. Ask: publish the PhpStorm plugin
   `<version>`? Wait for a clear yes.
2. Run the command in the background. It downloads each supported PhpStorm, so it can take many
   minutes:

   ```sh
   make phpstorm-publish
   ```

   The command reads the token from the macOS Keychain (the `phem-jetbrains-marketplace` item)
   or from `PUBLISH_TOKEN`. Do not read the token yourself. The project denies the `security`
   commands that print a Keychain item.

   The command builds the plugin against the oldest supported PhpStorm, verifies it against
   all the supported versions, and uploads it. It stops on a compatibility problem or on a
   missing or empty changelog section.
3. If the command says "No JetBrains Marketplace token", tell the user to make a token at
   https://plugins.jetbrains.com/author/me/tokens and to add it in their own terminal. The
   command asks for the value, so it does not go in the shell history:

   ```sh
   security add-generic-password -a "$USER" -s phem-jetbrains-marketplace -w
   ```

   Wait for the user, then run `make phpstorm-publish` again.
4. If the command fails, show the output and stop. Fix a compatibility problem in a normal
   change, not in the release.
5. JetBrains examines each new version before it shows on the Marketplace. The published version
   of step 2 changes only after the approval. Tell the user this, and give the link of the plugin
   page from the output, or https://plugins.jetbrains.com/author/me.

## 6. Report

Show a table: each plugin, its old published version, its new version, and the result
(published, waiting for the JetBrains review, skipped, or failed). Name each step that you
skipped and why.
