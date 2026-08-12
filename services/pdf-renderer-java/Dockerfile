# Build stage
FROM m.daocloud.io/docker.io/library/maven:3.9.6-eclipse-temurin-17 AS build
WORKDIR /app
RUN mkdir -p /root/.m2
COPY maven-settings.xml /root/.m2/settings.xml
COPY pom.xml .
RUN mvn -e -B -DskipTests dependency:go-offline
COPY src ./src
RUN mvn -e -B -DskipTests package

# Run stage
FROM m.daocloud.io/docker.io/library/eclipse-temurin:17-jre
WORKDIR /app
ENV JAVA_TOOL_OPTIONS="-XX:MaxRAMPercentage=75.0 -XX:InitialRAMPercentage=25.0 -XX:+UseG1GC -XX:MaxGCPauseMillis=200"
COPY --from=build /app/target/pdf-signer-*.jar app.jar
EXPOSE 8081
ENTRYPOINT ["java","-jar","/app/app.jar"]
