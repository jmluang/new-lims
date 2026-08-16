package com.luang.pdfsigner.execution;

import java.nio.channels.FileChannel;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.nio.file.StandardOpenOption;

public final class RetirementCrashWorker {
    private RetirementCrashWorker() {}

    public static void main(String[] arguments) throws Exception {
        String action = arguments[0];
        Path source = Path.of(arguments[1]);
        if ("move".equals(action)) {
            Path target = Path.of(arguments[2]);
            Files.move(source, target, StandardCopyOption.ATOMIC_MOVE);
            forceDirectory(source.getParent());
            forceDirectory(target.getParent());
        } else if ("unlink".equals(action)) {
            Files.delete(source);
            forceDirectory(source.getParent());
        } else {
            throw new IllegalArgumentException("Unknown retirement crash action");
        }
        System.out.println("retirement-file-action-durable");
        System.out.flush();
        Runtime.getRuntime().halt(137);
    }

    private static void forceDirectory(Path directory) throws Exception {
        try (FileChannel channel = FileChannel.open(directory, StandardOpenOption.READ)) {
            channel.force(true);
        }
    }
}
