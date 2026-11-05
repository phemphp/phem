package phem.intellij

import com.intellij.ide.FileIconProvider
import com.intellij.openapi.project.Project
import com.intellij.openapi.util.IconLoader
import com.intellij.openapi.vfs.VirtualFile
import javax.swing.Icon

/** Shows the Phem icon for the `.phem` files. The file type stays the TextMate file type. */
class PhemFileIconProvider : FileIconProvider {
    override fun getIcon(file: VirtualFile, flags: Int, project: Project?): Icon? =
        if (PhemPlugin.isPhemFile(file)) ICON else null

    companion object {
        val ICON: Icon = IconLoader.getIcon("/icons/phem.svg", PhemFileIconProvider::class.java)
    }
}
