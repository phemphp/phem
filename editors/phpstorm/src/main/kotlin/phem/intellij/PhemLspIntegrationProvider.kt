package phem.intellij

import com.intellij.execution.ExecutionException
import com.intellij.execution.configurations.GeneralCommandLine
import com.intellij.execution.configurations.PathEnvironmentVariableUtil
import com.intellij.openapi.project.Project
import com.intellij.openapi.project.ProjectManager
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspClientManager
import com.intellij.platform.lsp.api.LspIntegrationProvider
import com.intellij.platform.lsp.api.ProjectWideLspClientDescriptor
import com.intellij.platform.lsp.api.customization.LspCodeLensCustomizer
import com.intellij.platform.lsp.api.customization.LspCustomization
import java.io.File
import java.nio.charset.StandardCharsets
import java.nio.file.Files

/** Starts the Phem language server when the user opens a `.phem` file. */
class PhemLspIntegrationProvider : LspIntegrationProvider {
    override fun fileOpened(
        project: Project,
        file: VirtualFile,
        clientStarter: LspIntegrationProvider.LspClientStarter,
    ) {
        if (PhemPlugin.isPhemFile(file)) {
            clientStarter.ensureClientStarted(PhemLspClientDescriptor(project))
        }
    }

    companion object {
        /** Restarts the server in all open projects, for example after a change of the settings. */
        fun restartAll() {
            ProjectManager.getInstance().openProjects
                .filterNot { it.isDisposed }
                .forEach { restart(it) }
        }

        fun restart(project: Project) {
            LspClientManager.getInstance(project)
                .stopAndRestartClientsIfNeeded(PhemLspIntegrationProvider::class.java)
        }
    }
}

/**
 * Starts the server in one of two modes:
 *
 * - Native: the user sets a `phemc` path. The plugin runs `phemc lsp`.
 * - Bundled: the plugin runs `node --no-warnings server/phem-lsp.cjs` from its directory.
 *
 * Both modes use stdio.
 */
class PhemLspClientDescriptor(project: Project) : ProjectWideLspClientDescriptor(project, "Phem") {
    override fun isSupportedFile(file: VirtualFile): Boolean = PhemPlugin.isPhemFile(file)

    override fun getLanguageId(file: VirtualFile): String = PhemPlugin.LANGUAGE_ID

    override fun createCommandLine(): GeneralCommandLine {
        val settings = PhemSettings.getInstance()
        val commandLine = if (settings.phemcPath.isNotEmpty()) {
            GeneralCommandLine(executable(settings.phemcPath, "phemc"), "lsp")
        } else {
            val script = PhemPlugin.path()?.resolve("server/phem-lsp.cjs")
            if (script == null || !Files.isRegularFile(script)) {
                throw ExecutionException(
                    "The Phem plugin has no bundled server. " +
                        "Set the phemc path in Settings | Languages & Frameworks | Phem.",
                )
            }
            val node = executable(settings.nodePath.ifEmpty { "node" }, "Node.js")
            GeneralCommandLine(node, "--no-warnings", script.toString())
        }

        return commandLine
            .withCharset(StandardCharsets.UTF_8)
            .withParentEnvironmentType(GeneralCommandLine.ParentEnvironmentType.CONSOLE)
            .apply { project.basePath?.let { withWorkDirectory(it) } }
    }

    override val lspCustomization: LspCustomization = object : LspCustomization() {
        override val codeLensCustomizer: LspCodeLensCustomizer = PhemCodeLensSupport()
    }

    override fun createInitializationOptions(): Any =
        mapOf("sourceDir" to PhemProjectSettings.getInstance(project).sourceDir)

    /** Finds an executable by its path, or by its name in PATH. */
    private fun executable(command: String, label: String): String {
        val file = File(command)
        if (file.isAbsolute || command.contains(File.separatorChar)) {
            if (!file.canExecute()) {
                throw ExecutionException("The $label executable \"$command\" does not exist or cannot run.")
            }
            return file.path
        }
        return PathEnvironmentVariableUtil.findExecutableInPathOnAnyOS(command)?.path
            ?: throw ExecutionException(
                "Cannot find \"$command\" in PATH. Set the $label path in Settings | Languages & Frameworks | Phem.",
            )
    }
}
