#!/bin/bash

if [ "$EUID" -ne 0 ]
  then echo "Please run as root"
  exit
fi

mkdir -p /usr/local/mtvcli
if [ -f mtvcli.phar ]
then
	mv -f mtvcli.phar /usr/local/mtvcli/mtvcli.phar
else
	echo "Downloading mtvcli..."
	wget -q -O /usr/local/mtvcli/mtvcli.phar https://github.com/DaSpors/mtvcli/raw/master/mtvcli.phar
fi

echo
echo "Creating local files..."

echo "#!/bin/bash" > /usr/bin/mtvcli
echo "php -d phar.readonly=0 /usr/local/mtvcli/mtvcli.phar \"\$@\"" >> /usr/bin/mtvcli
chmod 0755 /usr/bin/mtvcli

echo "_mtvcli()" > /etc/bash_completion.d/mtvcli
echo "{" >> /etc/bash_completion.d/mtvcli
echo "    local cur opts job cmdline" >> /etc/bash_completion.d/mtvcli
echo "    job=\"\${COMP_WORDS[0]}\"" >> /etc/bash_completion.d/mtvcli
echo "    cur=\"\${COMP_WORDS[COMP_CWORD]}\"" >> /etc/bash_completion.d/mtvcli
echo "    cmdline=\$(printf \"%s \" \"\${COMP_WORDS[@]}\")" >> /etc/bash_completion.d/mtvcli
echo "    opts=\$(\${job} __check__ \${cmdline})" >> /etc/bash_completion.d/mtvcli
echo "    if [ -z \"\${opts}\" ] ; then" >> /etc/bash_completion.d/mtvcli
echo "        return 1;" >> /etc/bash_completion.d/mtvcli
echo "    fi" >> /etc/bash_completion.d/mtvcli
echo "    COMPREPLY=( \$(compgen -W \"\${opts}\" -- \${cur}) )" >> /etc/bash_completion.d/mtvcli
echo "    return 0;" >> /etc/bash_completion.d/mtvcli
echo "}" >> /etc/bash_completion.d/mtvcli
echo "complete -o default -F _mtvcli mtvcli" >> /etc/bash_completion.d/mtvcli
chmod 0644 /etc/bash_completion.d/mtvcli
. /etc/bash_completion.d/mtvcli

echo "Done."
echo "Type 'mtvcli' for details."
