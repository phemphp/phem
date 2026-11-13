# Release a version

A release publishes the `phem/phem` Composer package (on Packagist, from the tag) and the `phemc` binaries (on the GitHub release). `vendor/bin/phemc` downloads the binary that matches the installed `phem/phem` version, so the compiler and the runtime always share a version. The VS Code extension and the PhpStorm plugin have their own versions and aren’t part of a release. Build them with `make vscode` and `make phpstorm`. Upload the first version of the PhpStorm plugin manually in the JetBrains Marketplace. Publish the next versions with `PUBLISH_TOKEN=<token> make phpstorm-publish` (see `editors/phpstorm/README.md`).

### Check the release prerequisites

Prepare your machine before you start:

- Install `git`, `gh`, and `curl`.
- Sign in with `gh auth login`. The token needs the `repo` and `workflow` scopes, which `gh auth status` shows.
- Check out `main`. It must be clean and match `origin/main`. The script checks this first.

### Run the release script

Run `./release.sh`. A menu shows the latest release and the next versions:

```text
Latest release: v0.1.0
? Which release?
  patch  v0.1.1
> minor  v0.2.0
  major  v1.0.0
  other version
```

Select a version with the arrow keys (or `j` and `k`) and press **Enter**. Select `other version` to type a version, for example the pre-release `0.2.0-beta.1`. A pre-release doesn’t become the latest release.

The script then runs every step and shows the progress of each workflow:

1. It asks you to confirm the version.
2. It dispatches the `Release` workflow and watches it. This workflow sets the compiler version, commits it to `main`, creates the tag, drafts the GitHub release, and starts `phemc.yaml`.
3. It watches `phemc.yaml`. That workflow builds the `phemc` binaries (Linux x86_64 and aarch64, macOS arm64 and x86_64, Windows x86_64), attests them, and uploads them with `phemc-checksums.txt` to the release.
4. It checks that the release has all the binaries and `phemc-checksums.txt`.
5. It asks you to publish the draft release. `vendor/bin/phemc` only downloads from a published release. If you answer `No`, the script shows the command to publish it later.
6. It waits until Packagist has the version.

After the release, run `git pull`, because the `Release` workflow pushed a commit to `main`.

### Pass the version on the command line

Skip the menu or the prompts with these arguments:

| Command | Result |
|---|---|
| `./release.sh` | Select the version in a menu |
| `./release.sh patch` | Bump the latest stable tag. Also `minor` and `major`. |
| `./release.sh 0.2.0-beta.1` | Release this version (no `v` prefix) |
| `./release.sh --yes minor` | Don’t ask: confirm the version and publish |

Without a terminal (in a pipe, for example), pass the version. The prompts then read `y` or `n`.

### Resume a failed release

When a step fails, the script stops and shows the run URL and the command to read its logs. Fix the cause and run the script again with the exact version, for example `./release.sh 0.2.0`. The `Release` workflow resumes from the tag. Don’t pass `patch`, `minor`, or `major` here: the new tag already exists, so they’d pick the version after it.

If Packagist doesn’t have the version after 5 minutes, check the GitHub hook of the package on [the phem/phem Packagist page](https://packagist.org/packages/phem/phem).

### Update the Flex recipe

`recipe/` is a copy of the `phem/phem` recipe in [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), under `phem/phem/<version>/`. Flex uses the recipe with the highest version that isn’t higher than the installed `major.minor` version. When a release needs different recipe files, change `recipe/` and send a pull request to `symfony/recipes-contrib` with a new `<major>.<minor>` directory.
