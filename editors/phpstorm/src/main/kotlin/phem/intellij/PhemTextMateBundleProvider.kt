package phem.intellij

import org.jetbrains.plugins.textmate.api.TextMateBundleProvider
import org.jetbrains.plugins.textmate.api.TextMateBundleProvider.PluginBundle
import java.nio.file.Files

/**
 * Gives the TextMate bundle of the plugin to the IDE. The build copies the bundle from the
 * VS Code extension into the `bundles/phpm` directory of the plugin.
 */
class PhemTextMateBundleProvider : TextMateBundleProvider {
    override fun getBundles(): List<PluginBundle> {
        val bundle = PhemPlugin.path()?.resolve("bundles/phpm") ?: return emptyList()
        if (!Files.isDirectory(bundle)) return emptyList()
        return listOf(PluginBundle("Phem", bundle))
    }
}
