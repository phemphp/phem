package phem.intellij

import com.intellij.ide.plugins.cl.PluginAwareClassLoader
import com.intellij.openapi.vfs.VirtualFile
import java.nio.file.Path

/** Shared values of the plugin. */
object PhemPlugin {
    const val EXTENSION = "phem"
    const val LANGUAGE_ID = "phem"

    /** The installation directory of the plugin, or null when the IDE does not know it. */
    fun path(): Path? =
        (PhemPlugin::class.java.classLoader as? PluginAwareClassLoader)?.pluginDescriptor?.pluginPath

    fun isPhemFile(file: VirtualFile): Boolean =
        !file.isDirectory && file.extension.equals(EXTENSION, ignoreCase = true)
}
