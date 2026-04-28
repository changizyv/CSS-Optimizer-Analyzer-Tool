# CSS Optimizer & Analyzer Tool

A powerful, standalone PHP tool to clean, optimize, and analyze CSS files without any external dependencies. Perfect for shared hosting environments where Composer and npm are not available.

## Features

- 🧹 **Remove Duplicate Rules** - Automatically finds and removes duplicate CSS rules, keeping the last occurrence
- 📦 **Minify CSS** - Compress CSS files by removing whitespace and comments
- 🔍 **Conflict Detection** - Identifies conflicting CSS rules across multiple files with specificity calculation
- ⚡ **Full Optimization** - Complete cleanup with user confirmation for conflicts
- 💾 **Automatic Backups** - Creates backups before any modification
- ↩️ **Undo Functionality** - Restore previous state with one click
- 👁️ **Dry Run Mode** - Preview changes without saving
- 📊 **Detailed Reports** - Shows statistics, file sizes, and conflict analysis
- 🎨 **Modern UI** - Clean, responsive interface with FontAwesome icons

## Requirements

- PHP 5.6 or higher
- No external libraries or Composer required
- Write permissions on CSS folder

## Installation

1. Download `css-optimizer.php` to your project root or CSS folder
2. Ensure the file has execute permissions
3. Access via web browser: `http://yourdomain.com/css-optimizer.php`

## Usage

1. Enter the path to your CSS folder (absolute or relative)
2. Select an operation:
   - **Clean Duplicates** - Removes duplicate rules automatically
   - **Minify** - Compresses CSS files
   - **Analyze Conflicts** - Detects conflicts without modifying files
   - **Full Optimize** - Complete optimization with conflict review
3. Enable Dry Run mode to preview changes
4. Click "Start Processing"
5. Review conflicts if any (for Full Optimize mode)
6. Use "Undo" button to restore from backup if needed

## How It Works

The tool:
1. Scans all `.css` files in the specified folder
2. Extracts CSS rules with metadata (file source, specificity, line position)
3. Detects conflicts by comparing selectors and properties
4. Calculates CSS specificity to determine which rule takes precedence
5. Presents conflicts for user review and confirmation
6. Removes duplicates and minifies based on user choices
7. Creates backups before any changes

## Conflict Resolution

When conflicts are detected, the tool:
- Shows each conflicting selector and property
- Displays all conflicting values and their source files
- Indicates which rule would win based on CSS specificity
- Allows you to choose "Keep Latest" or "Skip" for each conflict

## Author

**Hashem Changizy**
- GitHub: [@changizyv](https://github.com/changizyv)

## A Message from the Creator

**To the great and heroic people of Iran,**

In a country where the internet is deliberately severed and voices are silenced, access to information and free communication is not a luxury - it is a fundamental human right. The regime's widespread internet blackouts are nothing more than a tool of oppression, designed to hide atrocities and prevent the world from witnessing the courage of the Iranian people.

I stand with every Iranian fighting for freedom. I hear your voices through the cracks in the firewall, through the satellite signals, through every act of defiance. This tool, like every line of code I write, is a small contribution to the open and free world that Iran and all oppressed nations deserve.

**To my brothers and sisters in Iran:**

Your bravery in the face of brutality inspires the world. The regime fears your unity, your awareness, and your demand for basic rights. They cut the fiber optics, but they cannot cut your spirit. Every street protest, every raised voice, every woman refusing the hijab, every man refusing to be silent - these are the real forces that will bring down this dictatorship.

This project is dedicated to you - the doctors, teachers, students, workers, and mothers of Iran. You are the true heroes. You are the reason I believe in change.

**For freedom. For justice. For Iran.**

The day will come when every Iranian can access information freely, speak without fear, and live with dignity. Until that day, I will code, I will speak, and I will stand with you.

*Let them sever the internet - they cannot sever our resolve.*

## License

MIT License - Free for any use, personal or commercial.

## Contributing

Issues and pull requests are welcome. This tool aims to remain dependency-free and easy to deploy on any PHP hosting environment.

## Support

For issues or suggestions, please open an issue on GitHub or contact the author directly.
