

Option 1: Implemented
/compose

opens text editor, selectable in settings

new tmp file created

user adds contaxt, saves tmp file and exists

tmp file is sent to AI like any other context

AI process tmp file contents thru its turn logic and returns answer, etc

Option 2: 
/compose --bootstrap

The shell reads agent_bash_boot.md if you used --bootstrap.
It opens the editor with:
    the boot prompt text
then your existing compose draft, if any

You edit and save.
The saved editor contents come back into the shell.
If an AI provider is available, the shell immediately runs a normal AI turn.
That turn is sent as:
user_input: "Process the composed context and respond to it."
context: the full edited compose text
The model replies in the terminal through the usual turn/contract flow.



Option 3: To-Do
/compose loop

instead of replying in terminal AI output is added to end of tmp file and loaded back into new editor to epeat process.

:End loop
loop ends when user exits file without saving and/or exitin editor without saving. (file = editor) no changes made to file.
