#!/usr/bin/env perl
use strict;
use warnings;
use Cwd qw(getcwd);
use File::Basename qw(basename);

my $hook_type = shift @ARGV // '';
my $event = shift @ARGV // '';
my $project = '';
my $skip_next = 0;
for my $arg (@ARGV) {
    if ($skip_next) {
        $skip_next = 0;
        next;
    }
    if ($arg eq '--language' || $arg eq '--framework') {
        $skip_next = 1;
        next;
    }
    next if $arg =~ /^--(?:language|framework)=/ || $arg eq '--no-restart' || $arg eq '--force' || $arg =~ /^--/;
    $project = $arg;
    last;
}
$project = basename(getcwd()) if $project eq '';

if ($event eq 'project:up:before' || $event eq 'project:up:after') {
    print "Хук $hook_type видит, что проект $project запущен.\n";
} else {
    print "Команда $event не поддерживается этим хуком.\n";
}
