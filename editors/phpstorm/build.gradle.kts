import org.jetbrains.changelog.Changelog
import org.jetbrains.intellij.platform.gradle.IntelliJPlatformType

plugins {
    id("org.jetbrains.kotlin.jvm") version "2.4.21"
    id("org.jetbrains.intellij.platform") version "2.19.0"
    id("org.jetbrains.changelog") version "2.5.0"
}

group = "phem"
version = providers.gradleProperty("pluginVersion").get()

val phpstormPath = providers.gradleProperty("phpstormPath").getOrElse("/Applications/PhpStorm.app")
val phpstormVersion = providers.gradleProperty("phpstormVersion").getOrElse("")
val phpstormMinVersion = providers.gradleProperty("phpstormMinVersion").get()
// 2026.2 -> 262.
val pluginSinceBuild = phpstormMinVersion.split(".").let { (year, release) -> year.takeLast(2) + release }
val serverDir = layout.projectDirectory.dir(providers.gradleProperty("phemServerDir").getOrElse("../server"))
val vscodeDir = layout.projectDirectory.dir(providers.gradleProperty("phemVscodeDir").getOrElse("../vscode"))

repositories {
    mavenCentral()
    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        if (phpstormVersion.isNotBlank()) {
            create(IntelliJPlatformType.PhpStorm, phpstormVersion)
        } else {
            local(phpstormPath)
        }
        bundledPlugin("org.jetbrains.plugins.textmate")
        pluginVerifier()
    }
}

kotlin {
    jvmToolchain(21)
}

// CHANGELOG.md has a "## <version>" section for each version, with a list of the changes.
changelog {
    groups.empty()
}

// Set verifyRecommended=true to verify against all the PhpStorm versions that the plugin supports.
// It downloads each IDE.
val verifyRecommended = providers.gradleProperty("verifyRecommended").map { it.toBoolean() }.getOrElse(false)

intellijPlatform {
    pluginConfiguration {
        version = project.version.toString()
        // The section of the version, as HTML. Only the tasks that need it read it, and they fail
        // when the section is missing or empty.
        changeNotes = provider {
            val pluginVersion = project.version.toString()
            val item = changelog.get(pluginVersion).withHeader(false).withEmptySections(false)
            val html = changelog.renderItem(item, Changelog.OutputType.HTML)

            if (html.isBlank()) {
                throw GradleException("The \"## $pluginVersion\" section of CHANGELOG.md has no changes.")
            }

            html
        }
        ideaVersion {
            sinceBuild = pluginSinceBuild
            untilBuild = provider { null }
        }
    }
    // publishPlugin uploads a new version. The first version must go up in the Marketplace UI.
    publishing {
        token = providers.environmentVariable("PUBLISH_TOKEN")
    }
    // verifyPlugin checks the plugin against the same IDE as the build.
    pluginVerification {
        ides {
            if (verifyRecommended) {
                recommended()
            } else if (phpstormVersion.isNotBlank()) {
                create(IntelliJPlatformType.PhpStorm, phpstormVersion)
            } else {
                local(file(phpstormPath))
            }
        }
    }
    // Build the searchable options only on request. This step starts the IDE.
    buildSearchableOptions = false
}

// The IntelliJ icons are 16x16. The VS Code icon has a 32x32 view box, so set the size.
val generateIcons = tasks.register("generateIcons") {
    val source = vscodeDir.file("icons/phem.svg")
    val output = layout.buildDirectory.dir("generated/phem-resources")
    inputs.file(source)
    outputs.dir(output)
    doLast {
        val svg = source.asFile.readText()
        fun sized(size: Int) = svg.replaceFirst("<svg ", "<svg width=\"$size\" height=\"$size\" ")
        val dir = output.get().asFile
        dir.resolve("icons").mkdirs()
        dir.resolve("META-INF").mkdirs()
        dir.resolve("icons/phem.svg").writeText(sized(16))
        dir.resolve("META-INF/pluginIcon.svg").writeText(sized(40))
    }
}

sourceSets {
    main {
        resources.srcDir(generateIcons)
    }
}

// The language server. The build continues without it, but the plugin then needs a phemc path.
val serverFiles = listOf("phem-lsp.cjs", "phem-lsp.wasm")

// Warns when the server files are missing. The task has no outputs, so it runs on each build.
val checkServer = tasks.register("checkServer") {
    val files = serverFiles.map { serverDir.file(it).asFile }
    doLast {
        files.filterNot { it.isFile }.forEach {
            logger.warn("WARNING: ${it.path} does not exist. The plugin has no bundled server.")
        }
    }
}

tasks {
    prepareSandbox {
        val pluginDir = pluginName
        // The TextMate bundle: the grammar and the language configuration of the VS Code
        // extension. Its package.json gives the language, the extension, and the grammar.
        from(vscodeDir) {
            include("package.json")
            include("language-configuration.json")
            include("markup-language-configuration.json")
            include("syntaxes/phem.tmLanguage.json")
            into(pluginDir.map { "$it/bundles/phem" })
        }
        from(serverDir) {
            serverFiles.forEach { include(it) }
            into(pluginDir.map { "$it/server" })
        }
        dependsOn(checkServer)
    }
}
